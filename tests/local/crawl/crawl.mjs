/**
 * crawl.mjs — زاحف فجالة ستور لفحص الواجهة والأخطاء والتأكد من أمان البيانات
 * نظام الفجالة ستور المالي — PHP 8.3 + MySQL
 * تحديث بريف ٨٠١b: معالجة تسريب الذاكرة وكتابة التقارير تدريجياً
 * تحديث بريف ٨٠١c: تغطية كل الصفحات (طابور أولويات) ومعالجة ٣ إنذارات كاذبة (الحارس، إكسيل، وسجل الأخطاء)
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { execFileSync } from 'node:child_process';
import { chromium } from 'playwright-core';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const REPO_ROOT = path.resolve(__dirname, '../../..');

// 🔴 قايمة الـPOST المسموح بيها للقراءة فقط (فاضية افتراضياً حسب البريف بند ١٩)
// متضيفش فيها حاجة من غير موافقة صريحة
const READ_ONLY_POST = [];

// مجلد المخرجات
const outDir = path.join(__dirname, 'out');
const shotsDir = path.join(outDir, 'shots');

// ==========================================
// حالة التشغيل المرجعية للتقارير
// ==========================================
let options = null;
let startTime = Date.now();
let dbCountsBefore = {
  journal_entries: null,
  push_queue: null,
  sale_docs: null,
  notifications: null,
  activity_log: null
};
let dbCountsAfter = {
  journal_entries: null,
  push_queue: null,
  sale_docs: null,
  notifications: null,
  activity_log: null
};
let dbMatched = null;
let intentionalTestResult = null;
let guardSelfTestResult = null;
const crawledPages = [];
const pageErrors = [];
const blockedRequests = [];
const readOnlyPostCandidates = [];
let totalClicks = 0;
let credentials = null;
let normalExitCompleted = false;
let reportSavedOnExit = false;

const state = {
  isLoggingIn: false,
  currentPageUrl: '',
  currentAction: 'initial-load'
};

// ==========================================
// قراءة المدخلات والخيارات
// ==========================================
function parseArgs() {
  const args = process.argv.slice(2);
  const opts = {
    port: parseInt(process.env.CRAWL_PORT || '8095', 10),
    host: '127.0.0.1',
    baseUrl: process.env.CRAWL_BASE_URL || '',
    maxPages: 400, // بند ١ في بريف ٨٠١c: الحد الافتراضي ٤٠٠
    maxClicks: 40,
    user: process.env.CRAWL_USER || '',
    pass: process.env.CRAWL_PASS || '',
    usersList: process.env.CRAWL_USERS || '',
    roles: 'owner',
    headless: true,
    skipShots: false,
    pollTest: false,
    wwwDir: process.env.LARAGON_WWW || 'D:/laragon/www'
  };

  for (let i = 0; i < args.length; i++) {
    const a = args[i];
    if (a === '--port' && args[i + 1]) opts.port = parseInt(args[++i], 10);
    else if (a === '--host' && args[i + 1]) opts.host = args[++i];
    else if (a === '--base-url' && args[i + 1]) opts.baseUrl = args[++i];
    else if (a === '--max-pages' && args[i + 1]) opts.maxPages = parseInt(args[++i], 10);
    else if (a === '--max-clicks' && args[i + 1]) opts.maxClicks = parseInt(args[++i], 10);
    else if (a === '--user' && args[i + 1]) opts.user = args[++i];
    else if (a === '--pass' && args[i + 1]) opts.pass = args[++i];
    else if (a === '--roles' && args[i + 1]) opts.roles = args[++i];
    else if (a === '--headed' || a === '--no-headless') opts.headless = false;
    else if (a === '--skip-shots') opts.skipShots = true;
    else if (a === '--poll-test') opts.pollTest = true;
    else if (a === '--www' && args[i + 1]) opts.wwwDir = args[++i];
  }

  if (!opts.baseUrl) {
    opts.baseUrl = `http://${opts.host}:${opts.port}`;
  }

  return opts;
}

// ==========================================
// فحص عدادات الجداول الخمسة في الداتابيز
// ==========================================
function getDbCounts(wwwDir) {
  const phpBin = 'D:/laragon/bin/php/php-8.3.33-Win32-vs16-x64/php.exe';
  const scriptPath = path.join(__dirname, 'db_check.php');
  try {
    const env = { ...process.env, LARAGON_WWW: wwwDir };
    const stdout = execFileSync(phpBin, [scriptPath], { env, encoding: 'utf-8', timeout: 5000 });
    const data = JSON.parse(stdout.trim());
    if (data && data.ok) {
      return data.counts;
    }
  } catch (err) {
    console.warn('[crawl] تحذير: تعذر قراءة عدادات الداتابيز عبر php:', err.message);
  }
  return {
    journal_entries: null,
    push_queue: null,
    sale_docs: null,
    notifications: null,
    activity_log: null
  };
}

// ==========================================
// قراءة أيقونات Tabler المعتمدة في الـsubset
// ==========================================
function loadSubsetIcons(repoRoot) {
  const subsetPath = path.join(repoRoot, 'site/assets/tabler-subset.css');
  const validIcons = new Set();
  try {
    if (fs.existsSync(subsetPath)) {
      const content = fs.readFileSync(subsetPath, 'utf-8');
      const matches = content.match(/\.ti-[a-z0-9-]+/g) || [];
      for (const m of matches) {
        validIcons.add(m.replace(/^\./, ''));
      }
    }
  } catch (e) {
    console.warn('[crawl] تحذير أثناء قراءة tabler-subset.css:', e.message);
  }
  return validIcons;
}

// ==========================================
// فحص التنزيل والتصدير إكسيل (بند ٣ في بريف ٨٠١c)
// ==========================================
async function verifyAndHandleDownload(download) {
  try {
    const stream = await download.createReadStream();
    const chunks = [];
    for await (const chunk of stream) {
      chunks.push(chunk);
    }
    const buffer = Buffer.concat(chunks);
    const sizeBytes = buffer.length;
    const sizeKb = (sizeBytes / 1024).toFixed(1);

    // 🔴 حذف الملف فوراً دون حفظه حسب البريف
    await download.delete().catch(() => {});

    // فحص الحجم: لو صفر ← خطأ حقيقي
    if (sizeBytes === 0) {
      return {
        ok: false,
        sizeBytes: 0,
        sizeKb: '0',
        error: 'حجم ملف التنزيل/التصدير صفر بايت'
      };
    }

    // فحص لو رجع HTML بدل xlsx
    // ملفات Excel الحديثة (xlsx) هي ZIP Archive تبدأ ببايتات PK (0x50 0x4B 0x03 0x04)
    const isZip = buffer.length >= 4 && buffer[0] === 0x50 && buffer[1] === 0x4B;
    const previewText = buffer.slice(0, 150).toString('utf-8').trim();
    const isHtml = previewText.startsWith('<') || previewText.toLowerCase().includes('<html') || previewText.includes('Fatal error') || previewText.includes('Warning:');

    if (!isZip || isHtml) {
      return {
        ok: false,
        sizeBytes,
        sizeKb,
        error: `التصدير رجع HTML أو نص خطأ بدل ملف xlsx: ${previewText.slice(0, 80)}`
      };
    }

    return {
      ok: true,
      sizeBytes,
      sizeKb,
      message: `تصدير شغال (${sizeKb} KB)`
    };
  } catch (err) {
    return {
      ok: false,
      sizeBytes: 0,
      sizeKb: '0',
      error: `تعذر فحص التنزيل: ${err.message}`
    };
  }
}

// ==========================================
// مقارنة الصفحات الموجودة في site/*.php بالتي زُحفت (بند ١ في بريف ٨٠١c)
// ==========================================
function getUncrawledSitePages(crawledPagesList, repoRoot, wwwDir) {
  let siteDir = path.join(repoRoot, 'site');
  if (!fs.existsSync(siteDir) && wwwDir && fs.existsSync(wwwDir)) {
    siteDir = wwwDir;
  }
  if (!fs.existsSync(siteDir)) return [];

  let files = [];
  try {
    files = fs.readdirSync(siteDir);
  } catch (e) {
    console.warn('[crawl] تعذر قراءة مجلد site:', e.message);
    return [];
  }

  const phpFiles = files.filter(f => {
    if (!f.endsWith('.php')) return false;
    if (f.startsWith('.')) return false;
    // (مش _api / _lib / _core / includes)
    if (f.includes('_api') || f.includes('_lib') || f.includes('_core')) return false;
    if (f.startsWith('test_') || f.startsWith('_crawl_')) return false;
    return true;
  });

  const crawledFilenames = new Set();
  for (const p of crawledPagesList) {
    try {
      const u = new URL(p.url);
      const base = path.basename(u.pathname).toLowerCase();
      crawledFilenames.add(base);
    } catch {}
  }

  const uncrawled = [];
  for (const f of phpFiles) {
    if (!crawledFilenames.has(f.toLowerCase())) {
      uncrawled.push(f);
    }
  }
  uncrawled.sort();
  return uncrawled;
}

// ==========================================
// بناء كائن التقرير للحفظ
// ==========================================
function buildReportData(isCompleted = false) {
  const durationSec = Math.round((Date.now() - startTime) / 1000);
  const top30Errors = pageErrors.slice(0, 30);
  const uncrawledPages = getUncrawledSitePages(crawledPages, REPO_ROOT, options ? options.wwwDir : null);

  return {
    meta: {
      generatedAt: new Date().toISOString(),
      durationSeconds: durationSec,
      baseUrl: (options && options.baseUrl) || '',
      authMode: credentials ? 'authenticated' : 'pre-auth-login-only',
      requiresAuthForFullRun: !credentials,
      status: isCompleted ? 'completed' : 'in_progress',
      totalPagesCrawled: crawledPages.length,
      totalClicks,
      totalErrors: pageErrors.length,
      totalBlockedRequests: blockedRequests.length,
      totalUncrawledSitePages: uncrawledPages.length
    },
    database_contract: {
      matched: dbMatched,
      counts_before: dbCountsBefore || {},
      counts_after: dbCountsAfter || {}
    },
    guard: {
      active: true,
      self_test: guardSelfTestResult || { passed: false },
      totalBlocked: blockedRequests.length,
      samples: blockedRequests.slice(0, 20),
      readOnlyPostCandidates: Array.from(new Set(readOnlyPostCandidates.map(c => c.url)))
    },
    intentional_js_error_test: intentionalTestResult || { passed: false, capturedError: 'لم يتم التشغيل بعد' },
    top_30_errors: top30Errors,
    pages: crawledPages,
    uncrawled_pages: uncrawledPages
  };
}

// بند ٤: حفظ التقرير report.json عبر ملف مؤقت ثم rename
function writeJsonReportSync(reportData) {
  try {
    fs.mkdirSync(outDir, { recursive: true });
    const jsonReportPath = path.join(outDir, 'report.json');
    const tempJsonPath = path.join(outDir, `report.json.tmp.${process.pid}`);
    fs.writeFileSync(tempJsonPath, JSON.stringify(reportData, null, 2), 'utf-8');
    try {
      fs.renameSync(tempJsonPath, jsonReportPath);
    } catch {
      fs.writeFileSync(jsonReportPath, JSON.stringify(reportData, null, 2), 'utf-8');
      if (fs.existsSync(tempJsonPath)) fs.unlinkSync(tempJsonPath);
    }
  } catch (e) {
    console.warn('[crawl] تحذير: فشل كتابة report.json:', e.message);
  }
}

// بند ٤: حفظ تقرير report.html
function writeHtmlReportSync(reportData) {
  try {
    fs.mkdirSync(outDir, { recursive: true });
    const htmlReportPath = path.join(outDir, 'report.html');
    const tempHtmlPath = path.join(outDir, `report.html.tmp.${process.pid}`);
    const htmlContent = generateHtmlReport(reportData);
    fs.writeFileSync(tempHtmlPath, htmlContent, 'utf-8');
    try {
      fs.renameSync(tempHtmlPath, htmlReportPath);
    } catch {
      fs.writeFileSync(htmlReportPath, htmlContent, 'utf-8');
      if (fs.existsSync(tempHtmlPath)) fs.unlinkSync(tempHtmlPath);
    }
  } catch (e) {
    console.warn('[crawl] تحذير: فشل كتابة report.html:', e.message);
  }
}

// حفظ تدريجي لـ report.json بعد كل صفحة
function saveIncrementalReport() {
  const data = buildReportData(false);
  writeJsonReportSync(data);
}

// حفظ نهائي للاثنين معا عند الانتهاء أو الخروج المفاجئ
function saveFinalReportsSync(isCompleted = false) {
  try {
    const data = buildReportData(isCompleted);
    writeJsonReportSync(data);
    writeHtmlReportSync(data);
  } catch (e) {
    console.error('[crawl] تعذر حفظ التقارير عند الإغلاق:', e.message);
  }
}

// بند ٤: ربط معالجات الخروج لحفظ التقرير عند أي توقف
process.on('exit', () => {
  if (!reportSavedOnExit) {
    reportSavedOnExit = true;
    saveFinalReportsSync(normalExitCompleted);
  }
});

process.on('uncaughtException', (err) => {
  console.error('[crawl] خطأ حرج (uncaughtException):', err);
  if (!reportSavedOnExit) {
    reportSavedOnExit = true;
    saveFinalReportsSync(false);
  }
  process.exit(1);
});

process.on('unhandledRejection', (reason) => {
  console.error('[crawl] رفض غير معالج (unhandledRejection):', reason);
  if (!reportSavedOnExit) {
    reportSavedOnExit = true;
    saveFinalReportsSync(false);
  }
  process.exit(1);
});

// ==========================================
// بند ٢ و بند ١ و بند ٣: دالة إنشاء صفحة مدارة
// ==========================================
async function createManagedPage(context, { onPageError, onConsoleError, onRequestActivity } = {}) {
  const page = await context.newPage();

  // بند ١: Map لمطابقة وقت البداية مع حذف المفتاح فور الاستجابة أو الفشل
  const requestTimes = new Map();

  // بند ٣: تخزين عداد الطلبات وأبطأ ٥ فقط لكل صفحة
  let requestCount = 0;
  const slowest5 = [];

  page.on('dialog', async (dialog) => {
    try { await dialog.dismiss(); } catch (e) {}
  });

  page.on('popup', async (popup) => {
    try { await popup.close(); } catch (e) {}
  });

  page.on('framenavigated', (frame) => {
    if (frame === page.mainFrame()) {
      state.currentPageUrl = frame.url();
    }
  });

  page.on('console', (msg) => {
    const text = msg.text();
    if (msg.type() === 'error' && !text.includes('ERR_BLOCKED_BY_CLIENT')) {
      if (onConsoleError) onConsoleError(text);
    }
  });

  page.on('pageerror', (err) => {
    const msg = err.message || String(err);
    if (onPageError) onPageError(msg);
  });

  // بند ١: تسجيل البداية
  page.on('request', (req) => {
    requestTimes.set(req, Date.now());
  });

  // بند ١ و بند ٣: حذف المفتاح فوراً وعدم تخزين كل الطلبات
  page.on('response', (resp) => {
    requestCount++;
    const req = resp.request();
    const startTimeReq = requestTimes.get(req);
    requestTimes.delete(req); // بند ١: مسح المفتاح فوراً لمنع تسريب الذاكرة

    let duration = startTimeReq ? Date.now() - startTimeReq : 0;
    if (duration <= 0) {
      try {
        const timing = req.timing();
        if (timing && timing.responseEnd > 0) {
          duration = Math.max(0, Math.round(timing.responseEnd));
        }
      } catch {}
    }

    // بند ٣: الاحتفاظ بأبطأ ٥ فقط
    if (slowest5.length < 5 || duration > slowest5[slowest5.length - 1].ms) {
      slowest5.push({ url: resp.url(), ms: duration });
      slowest5.sort((a, b) => b.ms - a.ms);
      if (slowest5.length > 5) slowest5.length = 5;
    }

    const status = resp.status();
    // بند ٣: تخزين أخطاء الطلبات فقط
    if (status >= 400 && onRequestActivity) {
      onRequestActivity('http_error', { status, url: resp.url() });
    }
  });

  // بند ١: مسح المفتاح في حالة فشل الطلب
  page.on('requestfailed', (req) => {
    requestTimes.delete(req); // بند ١: مسح المفتاح فوراً
    const failure = req.failure();
    const errorText = failure ? failure.errorText : 'failed';
    // استثناء: net::ERR_ABORTED أو Download is starting عند بدء التنزيل/التصدير ليس خطأ حقيقي للطلب (بند ٣)
    const isDownloadAbort = (errorText.includes('ERR_ABORTED') || errorText.includes('Download is starting')) &&
      (req.url().includes('export') || req.url().includes('download') || req.url().includes('.xlsx') || req.url().includes('.csv'));
    if (!errorText.includes('ERR_BLOCKED_BY_CLIENT') && !isDownloadAbort && onRequestActivity) {
      onRequestActivity('request_failed', { url: req.url(), errorText });
    }
  });

  return {
    page,
    getStats: () => ({
      requestCount,
      slowest5,
      slowestRequestMs: slowest5.length > 0 ? slowest5[0].ms : 0
    }),
    close: async () => {
      requestTimes.clear();
      try {
        await page.close();
      } catch (e) {}
    }
  };
}

// ==========================================
// تشغيل اختبار الخطأ المقصود في صفحة مؤقتة
// ==========================================
async function runIntentionalJsErrorTest(context, baseUrl, wwwDir) {
  const tempFileName = 'test_crawler_intentional_error_temp.php';
  const tempFilePath = path.join(wwwDir, tempFileName);
  const targetUrl = `${baseUrl}/${tempFileName}`;

  const htmlContent = `<!DOCTYPE html>
<html lang="ar">
<head><meta charset="UTF-8"><title>Intentional JS Error Test</title></head>
<body>
  <h1>صفحة اختبار الخطأ المقصود</h1>
  <script>
    // خطأ مقصود للتحقق من صيد الزاحف للأخطاء
    x.y();
  </script>
</body>
</html>`;

  let capturedError = null;
  const page = await context.newPage();
  const errorHandler = (err) => {
    capturedError = err.message || String(err);
  };

  try {
    fs.writeFileSync(tempFilePath, htmlContent, 'utf-8');
    page.on('pageerror', errorHandler);

    await page.goto(targetUrl, { waitUntil: 'load', timeout: 6000 }).catch(() => {});
    await page.waitForTimeout(500);

    return {
      passed: !!capturedError && capturedError.includes('x is not defined'),
      capturedError: capturedError || 'لم يتم التقاط الخطأ',
      tempFileCleaned: true
    };
  } catch (e) {
    return {
      passed: false,
      capturedError: e.message,
      tempFileCleaned: false
    };
  } finally {
    page.off('pageerror', errorHandler);
    await page.close().catch(() => {});
    try {
      if (fs.existsSync(tempFilePath)) {
        fs.unlinkSync(tempFilePath);
      }
    } catch (e) {
      console.warn('[crawl] تحذير أثناء مسح الصفحة المؤقتة:', e.message);
    }
  }
}

// ==========================================
// الدالة الرئيسية للزاحف
// ==========================================
async function main() {
  startTime = Date.now();
  options = parseArgs();
  state.currentPageUrl = `${options.baseUrl}/login.php`;

  console.log('[crawl] ====================================================');
  console.log('[crawl] بدء تشغيل زاحف فجالة ستور (بند ٨٠١ / ٨٠١b)');
  console.log(`[crawl] السيرفر: ${options.baseUrl}`);
  console.log(`[crawl] المجلد: ${options.wwwDir}`);
  console.log(`[crawl] الحد الأقصى للصفحات: ${options.maxPages}`);
  console.log('[crawl] ====================================================');

  // تحضير مجلدات المخرجات
  fs.mkdirSync(shotsDir, { recursive: true });

  // قراءة الأيقونات المعتمدة
  const validIcons = loadSubsetIcons(REPO_ROOT);
  console.log(`[crawl] عدد أيقونات الـsubset المعتمدة: ${validIcons.size}`);

  // فحص عدادات الداتابيز قبل الزحف
  console.log('[crawl] أخذ لقطة لعدادات الجداول الخمسة قبل الزحف...');
  dbCountsBefore = getDbCounts(options.wwwDir);
  console.log('[crawl] العدادات قبل:', JSON.stringify(dbCountsBefore));

  // تشغيل متصفح Edge الموجود على الجهاز
  let browser;
  try {
    browser = await chromium.launch({
      channel: 'msedge',
      headless: options.headless
    });
  } catch (err) {
    console.log('[crawl] محاولة تشغيل Edge عبر المسار المباشر...');
    browser = await chromium.launch({
      executablePath: 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
      headless: options.headless
    });
  }

  // عمل سياق التصفح
  const context = await browser.newContext({
    viewport: { width: 1440, height: 900 },
    locale: 'ar-EG'
  });

  // 🔴 🔴 الحارس — تفعيله على مستوى السياق context قبل أي صفحة (بند ٢: يفضل على context.route زي ما هو)
  await context.route('**/*', async (route) => {
    const req = route.request();
    const method = req.method().toUpperCase();
    const url = req.url();

    let urlObj;
    try {
      urlObj = new URL(url);
    } catch {
      urlObj = { pathname: url, search: '', searchParams: new URLSearchParams('') };
    }
    const pathAndQuery = (urlObj.pathname + (urlObj.search || '')).toLowerCase();

    // 1. فحص طلبات الكتابة والتعديل POST / PUT / PATCH / DELETE
    if (['POST', 'PUT', 'PATCH', 'DELETE'].includes(method)) {
      // استثناء: الدخول الأولي فقط على login.php
      if (state.isLoggingIn && (pathAndQuery.includes('login.php') || pathAndQuery === '/login')) {
        return route.continue();
      }

      // استثناء: القائمة البيضاء الصريحة
      const isWhitelisted = READ_ONLY_POST.some(item => pathAndQuery.includes(item.toLowerCase()));
      if (isWhitelisted) {
        return route.continue();
      }

      // فحص لو الـPOST شكله قراءة فقط للتسجيل في التقرير
      if (pathAndQuery.includes('get_') || pathAndQuery.includes('search') || pathAndQuery.includes('query') || pathAndQuery.includes('fetch')) {
        readOnlyPostCandidates.push({ method, url, path: urlObj.pathname });
      }

      // الحارس يقفل الطلب فوراً
      blockedRequests.push({
        method,
        url,
        path: urlObj.pathname,
        reason: `قفل طلب تعديل (${method}) بحارس الأمان`,
        page: state.currentPageUrl,
        action: state.currentAction,
        time: new Date().toISOString()
      });
      return route.abort('blockedbyclient');
    }

    // 2. فحص طلبات GET الخطرة التي قد تؤثر على الحالة أو الجلسة أو السيرفر (بند ٢ في بريف ٨٠١c)
    // الكلمات الخطرة تتفحص على اسم الملف (pathname) وعلى قيم بارامترات action/op/do/part/mode بس — مش على أي حتة في الـURL
    if (method === 'GET') {
      const dangerousPatterns = [
        'logout', 'delete', 'remove', 'pay', 'approve',
        'reject', 'close', 'reverse', 'cancel', 'post',
        'print', 'push'
      ];

      const pathname = (urlObj.pathname || '').toLowerCase();
      let matchedToken = dangerousPatterns.find(token => pathname.includes(token));
      let blockReason = matchedToken ? `اسم الملف يحتوي على كلمة '${matchedToken}'` : '';

      if (!matchedToken && urlObj.searchParams) {
        const actionParamNames = new Set(['action', 'op', 'do', 'part', 'mode']);
        for (const [key, val] of urlObj.searchParams.entries()) {
          if (actionParamNames.has(key.toLowerCase())) {
            const valLower = String(val).toLowerCase();
            const found = dangerousPatterns.find(token => valLower.includes(token));
            if (found) {
              matchedToken = found;
              blockReason = `بارامتر '${key}' يحتوي على قيمة خطرة '${found}'`;
              break;
            }
          }
        }
      }

      if (matchedToken) {
        blockedRequests.push({
          method,
          url,
          path: urlObj.pathname,
          reason: `قفل طلب GET خطر: ${blockReason}`,
          page: state.currentPageUrl,
          action: state.currentAction,
          time: new Date().toISOString()
        });
        return route.abort('blockedbyclient');
      }
    }

    return route.continue();
  });

  // ==========================================
  // دالة التقاط الصور الأربعة لكل صفحة
  // ==========================================
  async function takePageScreenshots(page, pageSlug) {
    if (options.skipShots) return {};
    const shots = {};
    const safeSlug = pageSlug.replace(/[^a-zA-Z0-9_-]/g, '_') || 'page';

    try {
      // 1. ديسكتوب فاتح
      await page.setViewportSize({ width: 1440, height: 900 });
      await page.evaluate(() => {
        document.documentElement.setAttribute('data-theme', 'light');
        try { localStorage.setItem('fagala-theme', 'light'); } catch (e) {}
      });
      await page.waitForTimeout(150);
      const dLightPath = path.join(shotsDir, `${safeSlug}_1440_light.png`);
      await page.screenshot({ path: dLightPath, fullPage: true });
      shots.desktop_light = path.relative(outDir, dLightPath).replace(/\\/g, '/');

      // 2. ديسكتوب ليلي
      await page.evaluate(() => {
        document.documentElement.setAttribute('data-theme', 'dark');
        try { localStorage.setItem('fagala-theme', 'dark'); } catch (e) {}
      });
      await page.waitForTimeout(150);
      const dDarkPath = path.join(shotsDir, `${safeSlug}_1440_dark.png`);
      await page.screenshot({ path: dDarkPath, fullPage: true });
      shots.desktop_dark = path.relative(outDir, dDarkPath).replace(/\\/g, '/');

      // 3. موبايل فاتح
      await page.setViewportSize({ width: 390, height: 844 });
      await page.evaluate(() => {
        document.documentElement.setAttribute('data-theme', 'light');
        try { localStorage.setItem('fagala-theme', 'light'); } catch (e) {}
      });
      await page.waitForTimeout(150);
      const mLightPath = path.join(shotsDir, `${safeSlug}_390_light.png`);
      await page.screenshot({ path: mLightPath, fullPage: true });
      shots.mobile_light = path.relative(outDir, mLightPath).replace(/\\/g, '/');

      // فحص الـscroll الأفقي على الموبايل
      const hScroll = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth);
      if (hScroll) {
        pageErrors.push({
          page: state.currentPageUrl,
          type: 'Mobile Horizontal Scroll',
          message: 'وجود سكرول أفقي على شاشة الموبايل (scrollWidth > innerWidth)',
          trigger: 'mobile-viewport-check',
          time: new Date().toISOString()
        });
      }

      // 4. موبايل ليلي
      await page.evaluate(() => {
        document.documentElement.setAttribute('data-theme', 'dark');
        try { localStorage.setItem('fagala-theme', 'dark'); } catch (e) {}
      });
      await page.waitForTimeout(150);
      const mDarkPath = path.join(shotsDir, `${safeSlug}_390_dark.png`);
      await page.screenshot({ path: mDarkPath, fullPage: true });
      shots.mobile_dark = path.relative(outDir, mDarkPath).replace(/\\/g, '/');

      // إعادة الحجم للديسكتوب
      await page.setViewportSize({ width: 1440, height: 900 });
    } catch (e) {
      console.warn(`[crawl] خطأ أثناء التقاط صور ${safeSlug}:`, e.message);
    }

    return shots;
  }

  // ==========================================
  // فحص أخطاء النصوص والأيقونات في الصفحة
  // ==========================================
  async function inspectPageDom(page, pageUrl) {
    try {
      // بند ٤ في بريف ٨٠١c: استثناء فحص النصوص (Warning/Fatal) في errors.php و activity.php لأنها تعرض سجل الأخطاء نفسه
      let isTextExempt = false;
      try {
        const u = new URL(pageUrl);
        const filename = path.basename(u.pathname).toLowerCase();
        if (filename === 'errors.php' || filename === 'activity.php') {
          isTextExempt = true;
        }
      } catch {}

      if (!isTextExempt) {
        const bodyText = await page.evaluate(() => document.body ? document.body.innerText : '');
        const patterns = [
          { regex: /Fatal error[:\s]/i, name: 'PHP Fatal error' },
          { regex: /Warning:\s/i, name: 'PHP Warning' },
          { regex: /Notice:\s/i, name: 'PHP Notice' },
          { regex: /Deprecated:\s/i, name: 'PHP Deprecated' },
          { regex: /Uncaught\s[a-zA-Z0-9_\\]*Exception/i, name: 'Uncaught Exception' },
          { regex: /Failed to fetch/i, name: 'Failed to fetch (واجهة)' }
        ];

        for (const p of patterns) {
          if (p.regex.test(bodyText)) {
            const lines = bodyText.split('\n').filter(l => p.regex.test(l)).slice(0, 3);
            pageErrors.push({
              page: pageUrl,
              type: p.name,
              message: lines.join(' | ') || p.name,
              trigger: state.currentAction,
              time: new Date().toISOString()
            });
          }
        }
      }

      // فحص أيقونات Tabler غير المعرفة بالـsubset (يستمر عادي لكل الصفحات)
      const usedIcons = await page.evaluate(() => {
        const els = document.querySelectorAll('[class*="ti-"]');
        const icons = [];
        els.forEach(el => {
          el.classList.forEach(cls => {
            if (cls.startsWith('ti-') && cls !== 'ti-spin') {
              icons.push(cls);
            }
          });
        });
        return Array.from(new Set(icons));
      });

      for (const ic of usedIcons) {
        if (!validIcons.has(ic)) {
          pageErrors.push({
            page: pageUrl,
            type: 'Missing Tabler Icon',
            message: `أيقونة غير موجودة في tabler-subset.css (مربع فاضي): ${ic}`,
            trigger: state.currentAction,
            time: new Date().toISOString()
          });
        }
      }
    } catch (e) {}
  }

  // ==========================================
  // تفاعل مع عناصر الصفحة
  // ==========================================
  async function interactWithPage(page, pageUrl, maxClicks) {
    let clicksThisPage = 0;

    // 1. الضغط على كل تبويب .ftabs
    try {
      state.currentAction = 'ftabs-click';
      const tabs = await page.$$('.ftabs button, .ftabs .ftab, .ftabs a, [role="tab"]');
      for (const tab of tabs) {
        if (clicksThisPage >= maxClicks) break;
        const isVis = await tab.isVisible().catch(() => false);
        if (isVis) {
          await tab.click({ timeout: 1500 }).catch(() => {});
          clicksThisPage++;
          totalClicks++;
          await page.waitForTimeout(300);
        }
      }
    } catch (e) {}

    // 2. تغيير أول اختيار في كل فلتر .ffilters select
    try {
      state.currentAction = 'ffilters-change';
      const selects = await page.$$('.ffilters select, select');
      for (const sel of selects) {
        const isVis = await sel.isVisible().catch(() => false);
        if (isVis) {
          await page.evaluate((el) => {
            if (el.options && el.options.length > 1) {
              const newIdx = el.selectedIndex === 0 ? 1 : 0;
              el.selectedIndex = newIdx;
              el.dispatchEvent(new Event('change', { bubbles: true }));
            }
          }, sel).catch(() => {});
          await page.waitForTimeout(300);
        }
      }
    } catch (e) {}

    // 3. الضغط على الأزرار الظاهرة والروابط النشطة
    try {
      state.currentAction = 'button-click';
      const actionables = await page.$$('button, a.btn, [data-action]');
      for (const btn of actionables) {
        if (clicksThisPage >= maxClicks) break;

        const isVis = await btn.isVisible().catch(() => false);
        const isDis = await btn.isDisabled().catch(() => false);
        if (!isVis || isDis) continue;

        const text = (await btn.innerText().catch(() => '')).trim();
        const href = await btn.getAttribute('href').catch(() => '');
        if (text.includes('خروج') || (href && href.includes('logout'))) {
          continue;
        }

        try {
          const isExportBtn = (href && href.includes('export')) || (text && (text.includes('تصدير') || text.includes('إكسيل')));
          const clickDlPromise = page.waitForEvent('download', { timeout: 3500 }).catch(() => null);

          await btn.click({ timeout: 1500 });
          clicksThisPage++;
          totalClicks++;

          let dl = null;
          if (isExportBtn) {
            dl = await clickDlPromise;
          } else {
            // فحص سريع لو نقرة الزرار أطلقت تنزيل
            dl = await Promise.race([
              clickDlPromise,
              new Promise(r => setTimeout(() => r(null), 400))
            ]);
          }

          if (dl) {
            const dlResult = await verifyAndHandleDownload(dl);
            if (!dlResult.ok) {
              pageErrors.push({
                page: pageUrl,
                type: 'Export Error',
                message: dlResult.error,
                trigger: `button: ${text || href}`,
                time: new Date().toISOString()
              });
            } else {
              console.log(`[crawl] ✅ نقرة زرار تصدير شغال: ${dlResult.message}`);
            }
          } else {
            await Promise.race([
              page.waitForLoadState('networkidle', { timeout: 1500 }).catch(() => {}),
              page.waitForTimeout(1500)
            ]);

            // إغلاق أي مودال بـ Escape
            await page.keyboard.press('Escape');

            // لو العنوان اتغير، ارجع لنفس الصفحة
            if (page.url() !== pageUrl) {
              if (page.url().includes('login.php')) {
                pageErrors.push({
                  page: pageUrl,
                  type: 'Unexpected Logout',
                  message: `الضغطة على الزرار أدت للخروج إلى login.php`,
                  trigger: `button: ${text || href}`,
                  time: new Date().toISOString()
                });
                if (credentials) {
                  await doLogin(credentials.user, credentials.pass);
                }
              }
              await page.goto(pageUrl, { waitUntil: 'domcontentloaded', timeout: 5000 }).catch(() => {});
            }
          }
        } catch (e) {}
      }
    } catch (e) {}

    return clicksThisPage;
  }

  // ==========================================
  // دالة الدخول (بند ٢: صفحة خاصة بها تغلق في finally)
  // ==========================================
  async function doLogin(username, password) {
    state.isLoggingIn = true;
    state.currentAction = 'login-attempt';
    const loginPage = await context.newPage();
    try {
      await loginPage.goto(`${options.baseUrl}/login.php`, { waitUntil: 'domcontentloaded', timeout: 10000 });
      await loginPage.fill('input[name="username"]', username);
      await loginPage.fill('input[name="password"]', password);
      await Promise.all([
        loginPage.waitForNavigation({ timeout: 6000 }).catch(() => {}),
        loginPage.click('button[type="submit"], input[type="submit"]')
      ]);
      const currentUrl = loginPage.url();
      state.isLoggingIn = false;
      const ok = !currentUrl.includes('login.php');
      let initialLinks = [];
      if (ok) {
        initialLinks = await loginPage.evaluate((base) => {
          const anchors = Array.from(document.querySelectorAll('nav a, header a, .sidebar a, .fsidebar a, .nav a, a[href]'));
          const found = [];
          for (const a of anchors) {
            try {
              const u = new URL(a.href, base);
              if (u.origin === new URL(base).origin && !u.pathname.includes('logout')) {
                if (!u.pathname.match(/\.(css|js|png|jpg|jpeg|gif|svg|woff2?|ico|json)$/i)) {
                  found.push(u.origin + u.pathname + u.search);
                }
              }
            } catch (e) {}
          }
          return Array.from(new Set(found));
        }, options.baseUrl).catch(() => []);
      }
      return { ok, currentUrl, initialLinks };
    } catch (e) {
      state.isLoggingIn = false;
      return { ok: false, currentUrl: '', initialLinks: [] };
    } finally {
      await loginPage.close().catch(() => {});
    }
  }

  // ==========================================
  // فحص وضع اختبار الذاكرة الصناعي (--poll-test)
  // ==========================================
  if (options.pollTest) {
    console.log('[crawl] ====================================================');
    console.log('[crawl] تشغيل اختبار الذاكرة الصناعي (polling test لمدة 60 ثانية)');
    console.log('[crawl] ====================================================');

    const pollTestFileName = '_crawl_poll_test.php';
    const pollTestFilePath = path.join(options.wwwDir, pollTestFileName);
    const pollUrl = `${options.baseUrl}/${pollTestFileName}`;

    const pollHtml = `<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><title>Crawl Memory Poll Test</title></head>
<body>
  <h1>Crawl Memory Poll Test</h1>
  <p>Testing continuous fetch requests every 50ms</p>
  <script>
    setInterval(() => {
      fetch('login.php').catch(() => {});
    }, 50);
  </script>
</body>
</html>`;

    try {
      fs.writeFileSync(pollTestFilePath, pollHtml, 'utf-8');
      console.log(`[crawl] تم إنشاء ملف الاختبار المؤقت: ${pollTestFilePath}`);

      const managed = await createManagedPage(context);
      try {
        console.log(`[crawl] فتح صفحة الاختبار: ${pollUrl}`);
        await managed.page.goto(pollUrl, { waitUntil: 'domcontentloaded', timeout: 10000 });

        const initialMemMb = Math.round(process.memoryUsage().heapUsed / 1024 / 1024);
        console.log(`[crawl] الذاكرة الابتدائية (heapUsed): ${initialMemMb} MB`);

        const memReadings = [initialMemMb];
        for (let sec = 10; sec <= 60; sec += 10) {
          await managed.page.waitForTimeout(10000);
          const currentMemMb = Math.round(process.memoryUsage().heapUsed / 1024 / 1024);
          const stats = managed.getStats();
          memReadings.push(currentMemMb);
          console.log(`[crawl] [poll-test] ثانية ${sec}/60 — الذاكرة: ${currentMemMb} MB (إجمالي الطلبات المستلمة: ${stats.requestCount})`);
        }

        const stats = managed.getStats();
        console.log('[crawl] ====================================================');
        console.log(`[crawl] ✅ انتهى اختبار الذاكرة الصناعي:`);
        console.log(`[crawl] - إجمالي الطلبات التي تمت معالجتها: ${stats.requestCount}`);
        console.log(`[crawl] - قراءات الذاكرة كل 10 ثوان: ${memReadings.join(' MB -> ')} MB`);
        console.log('[crawl] ====================================================');
      } finally {
        await managed.close();
      }
    } finally {
      try {
        if (fs.existsSync(pollTestFilePath)) {
          fs.unlinkSync(pollTestFilePath);
          console.log(`[crawl] تم مسح صفحة التجربة المؤقتة: ${pollTestFilePath}`);
        }
      } catch (err) {
        console.warn('[crawl] تحذير أثناء مسح صفحة التجربة:', err.message);
      }
    }

    await browser.close().catch(() => {});
    normalExitCompleted = true;
    reportSavedOnExit = true;
    return;
  }

  // ==========================================
  // اختبار الحارس الذاتي واختبار صفحة الخطأ
  // ==========================================
  console.log('[crawl] تشغيل الاختبار الذاتي للحارس (بند ٢ في بريف ٨٠١c)...');
  state.currentPageUrl = `${options.baseUrl}/login.php`;
  state.currentAction = 'guard-self-test';

  let tabPostedBlocked = false;
  let actionPostBlocked = false;

  {
    const selfTestPage = await context.newPage();
    try {
      await selfTestPage.goto(`${options.baseUrl}/login.php`, { waitUntil: 'domcontentloaded', timeout: 10000 });
      await selfTestPage.evaluate(() => {
        // محاولات استدعاء تُقفل بالحارس
        fetch('/api_guard_self_test_post', { method: 'POST' }).catch(() => {});
        fetch('/logout.php', { method: 'GET' }).catch(() => {});
        fetch('/invoice_delete.php?action=delete', { method: 'GET' }).catch(() => {});
        fetch('/x.php?action=post', { method: 'GET' }).catch(() => {});

        // محاولات استدعاء يجب أن تعدّي ولا تُقفل بالحارس (بند ٢)
        fetch('/invoices.php?tab=posted', { method: 'GET' }).catch(() => {});
        fetch('/invoices.php?tab=unposted', { method: 'GET' }).catch(() => {});
      });
      await selfTestPage.waitForTimeout(600);
    } finally {
      await selfTestPage.close().catch(() => {});
    }
  }

  tabPostedBlocked = blockedRequests.some(r => r.url.includes('tab=posted') || r.url.includes('tab=unposted'));
  actionPostBlocked = blockedRequests.some(r => r.url.includes('action=post'));

  guardSelfTestResult = {
    passed: !tabPostedBlocked && actionPostBlocked,
    tab_posted_allowed: !tabPostedBlocked,
    action_post_blocked: actionPostBlocked,
    totalBlockedInSelfTest: blockedRequests.length
  };

  console.log(`[crawl] نتيجة اختبار الحارس الذاتي:`);
  console.log(`[crawl] - /invoices.php?tab=posted عدّى بنجاح: ${!tabPostedBlocked ? '✅ نعم (لم يُقفل)' : '❌ فشل (تم قفله!)'}`);
  console.log(`[crawl] - /x.php?action=post اتقفل بالحارس: ${actionPostBlocked ? '✅ نعم (تم قفله)' : '❌ فشل (لم يُقفل!)'}`);
  console.log(`[crawl] - إجمالي الطلبات المقفولة في الاختبار الذاتي: ${blockedRequests.length}`);

  if (!guardSelfTestResult.passed) {
    console.error('[crawl] ❌ فشل في الاختبار الذاتي للحارس!');
  }

  console.log('[crawl] تشغيل اختبار الخطأ المقصود في صفحة مؤقتة (بند العقد ٤)...');
  state.currentAction = 'intentional-js-error-test';
  intentionalTestResult = await runIntentionalJsErrorTest(context, options.baseUrl, options.wwwDir);
  console.log(`[crawl] نتيجة اختبار الخطأ المقصود: ${intentionalTestResult.passed ? 'نجح (تم صيد الخطأ ومسح الملف)' : 'فشل'} — ${intentionalTestResult.capturedError}`);

  // حفظ تدريجي أولي بعد الاختبارات الأساسية
  saveIncrementalReport();

  // ==========================================
  // تحديد الحسابات ووضع التشغيل
  // ==========================================
  if (options.user && options.pass) {
    credentials = { user: options.user, pass: options.pass };
  } else if (options.usersList) {
    const firstPair = options.usersList.split(',')[0].trim();
    const parts = firstPair.split(':');
    if (parts.length >= 2) {
      credentials = { user: parts[0], pass: parts[1] };
    }
  }

  // ==========================================
  // تنفيذ الزحف: إما بدون تسجيل أو بتسجيل دخول كامل
  // ==========================================
  if (!credentials) {
    console.log('[crawl] ----------------------------------------------------');
    console.log('[crawl] ℹ️ لا توجد بيانات دخول في متغيرات البيئة CRAWL_USER / CRAWL_PASS.');
    console.log('[crawl] ℹ️ سيتم تشغيل الأداة وفحص شاشة الدخول والحارس والمطابقة.');
    console.log('[crawl] ----------------------------------------------------');

    const loginUrl = `${options.baseUrl}/login.php`;
    state.currentPageUrl = loginUrl;
    state.currentAction = 'login-screen-crawl';

    const pageErrorsThisPage = [];
    const managed = await createManagedPage(context, {
      onConsoleError: (text) => {
        const err = {
          page: loginUrl,
          type: 'Console Error',
          message: text,
          trigger: state.currentAction,
          time: new Date().toISOString()
        };
        pageErrors.push(err);
        pageErrorsThisPage.push(err);
      },
      onPageError: (msg) => {
        const err = {
          page: loginUrl,
          type: 'Uncaught Exception (pageerror)',
          message: msg,
          trigger: state.currentAction,
          time: new Date().toISOString()
        };
        pageErrors.push(err);
        pageErrorsThisPage.push(err);
      },
      onRequestActivity: (type, info) => {
        if (type === 'http_error') {
          const err = {
            page: loginUrl,
            type: `HTTP ${info.status}`,
            message: `استجابة برمز خطأ ${info.status} من الرابط: ${info.url}`,
            trigger: state.currentAction,
            time: new Date().toISOString()
          };
          pageErrors.push(err);
          pageErrorsThisPage.push(err);
        } else if (type === 'request_failed') {
          const err = {
            page: loginUrl,
            type: 'Request Failed',
            message: `فشل الطلب ${info.url}: ${info.errorText}`,
            trigger: state.currentAction,
            time: new Date().toISOString()
          };
          pageErrors.push(err);
          pageErrorsThisPage.push(err);
        }
      }
    });

    let navStatus = 200;
    try {
      const resp = await managed.page.goto(loginUrl, { waitUntil: 'domcontentloaded', timeout: 10000 });
      if (resp) navStatus = resp.status();

      await inspectPageDom(managed.page, loginUrl);
      const shots = await takePageScreenshots(managed.page, 'login');
      const clicksCount = await interactWithPage(managed.page, loginUrl, options.maxClicks);

      const stats = managed.getStats();
      crawledPages.push({
        url: loginUrl,
        slug: 'login',
        title: await managed.page.title().catch(() => 'تسجيل الدخول'),
        status: navStatus,
        clicks: clicksCount,
        requestsCount: stats.requestCount,
        slowestRequestMs: stats.slowestRequestMs,
        slowest5: stats.slowest5,
        errorsCount: pageErrorsThisPage.length,
        screenshots: shots
      });
    } finally {
      await managed.close();
    }

    // بند ٤: حفظ التقرير بعد الصفحة
    saveIncrementalReport();
  } else {
    console.log(`[crawl] تم العثور على حساب: ${credentials.user} — جاري تسجيل الدخول...`);
    const loginResult = await doLogin(credentials.user, credentials.pass);

    if (!loginResult.ok) {
      console.warn('[crawl] ⚠️ فشل تسجيل الدخول بالحساب المقدم. فحص شاشة الدخول فقط.');
      const loginUrl = `${options.baseUrl}/login.php`;
      state.currentPageUrl = loginUrl;

      const pageErrorsThisPage = [];
      const managed = await createManagedPage(context, {
        onConsoleError: (text) => {
          const err = {
            page: loginUrl,
            type: 'Console Error',
            message: text,
            trigger: state.currentAction,
            time: new Date().toISOString()
          };
          pageErrors.push(err);
          pageErrorsThisPage.push(err);
        },
        onPageError: (msg) => {
          const err = {
            page: loginUrl,
            type: 'Uncaught Exception (pageerror)',
            message: msg,
            trigger: state.currentAction,
            time: new Date().toISOString()
          };
          pageErrors.push(err);
          pageErrorsThisPage.push(err);
        }
      });

      try {
        await inspectPageDom(managed.page, loginUrl);
        const shots = await takePageScreenshots(managed.page, 'login_failed');
        const stats = managed.getStats();

        crawledPages.push({
          url: loginUrl,
          slug: 'login_failed',
          title: 'تسجيل الدخول (فشل)',
          status: 200,
          clicks: 0,
          requestsCount: stats.requestCount,
          slowestRequestMs: stats.slowestRequestMs,
          slowest5: stats.slowest5,
          errorsCount: pageErrorsThisPage.length,
          screenshots: shots
        });
      } finally {
        await managed.close();
      }

      saveIncrementalReport();
    } else {
      console.log('[crawl] ✅ تم تسجيل الدخول بنجاح! جاري استخراج الروابط من القائمة...');

      // بند ١ في بريف ٨٠١c: الطابور بأولويتين
      // (أ) كل pathname مختلف مرة واحدة الأول (من القايمة + الروابط)
      // (ب) بعدها تنويعات الـquery، بحد ٣ لكل pathname
      const seenPathnames = new Set();
      const visitedUrls = new Set();
      const queuedUrls = new Set();
      const queryVariationsCount = new Map();
      const queueA = []; // الأولوية الأولى: صفحات بـ pathname جديد
      const queueB = []; // الأولوية الثانية: تنويعات query (بحد ٣ لكل pathname)

      function enqueueUrl(urlStr) {
        let u;
        try {
          u = new URL(urlStr, options.baseUrl);
        } catch {
          return false;
        }

        if (u.origin !== new URL(options.baseUrl).origin) return false;
        if (u.pathname.includes('logout')) return false;
        if (u.pathname.match(/\.(css|js|png|jpg|jpeg|gif|svg|woff2?|ico|json|map)$/i)) return false;

        const normalizedUrl = u.origin + u.pathname + u.search;
        if (visitedUrls.has(normalizedUrl) || queuedUrls.has(normalizedUrl)) {
          return false;
        }

        const pathname = u.pathname.toLowerCase();

        // (أ) كل pathname مختلف مرة واحدة الأول
        if (!seenPathnames.has(pathname)) {
          seenPathnames.add(pathname);
          queuedUrls.add(normalizedUrl);
          queueA.push(normalizedUrl);
          return true;
        }

        // (ب) بعدها تنويعات الـquery بحد ٣ لكل pathname
        const count = queryVariationsCount.get(pathname) || 0;
        if (count < 3) {
          queryVariationsCount.set(pathname, count + 1);
          queuedUrls.add(normalizedUrl);
          queueB.push(normalizedUrl);
          return true;
        }

        return false;
      }

      // إضافة صفحة البداية بعد الدخول
      enqueueUrl(loginResult.currentUrl);

      // ترتيب روابط القائمة لتقديم الروابط النظيفة (بدون query) أولاً
      const sortedInitialLinks = [...loginResult.initialLinks].sort((a, b) => {
        const hasQ_a = a.includes('?') ? 1 : 0;
        const hasQ_b = b.includes('?') ? 1 : 0;
        return hasQ_a - hasQ_b;
      });

      for (const link of sortedInitialLinks) {
        enqueueUrl(link);
      }

      console.log(`[crawl] تم اكتشاف ${queueA.length} صفحة فريدة (Queue A) و ${queueB.length} تنويع query (Queue B) في القائمة الأولية.`);

      // بند ٢: صفحة جديدة context.newPage() لكل URL وpage.close() في finally
      while ((queueA.length > 0 || queueB.length > 0) && crawledPages.length < options.maxPages) {
        const targetUrl = queueA.length > 0 ? queueA.shift() : queueB.shift();
        if (visitedUrls.has(targetUrl)) continue;
        visitedUrls.add(targetUrl);

        state.currentPageUrl = targetUrl;
        state.currentAction = 'page-load';

        let urlObj;
        try { urlObj = new URL(targetUrl); } catch { urlObj = { pathname: 'page' }; }
        const slug = urlObj.pathname.replace(/^\//, '').replace(/\.php$/, '') || 'home';

        const memMb = Math.round(process.memoryUsage().heapUsed / 1024 / 1024);
        console.log(`[crawl] (${crawledPages.length + 1}/${options.maxPages}) [طابور أ: ${queueA.length} | طابور ب: ${queueB.length}] زحف: ${slug} (${targetUrl}) | الذاكرة: ${memMb} MB`);

        const pageErrorsThisPage = [];
        const managed = await createManagedPage(context, {
          onConsoleError: (text) => {
            const err = {
              page: targetUrl,
              type: 'Console Error',
              message: text,
              trigger: state.currentAction,
              time: new Date().toISOString()
            };
            pageErrors.push(err);
            pageErrorsThisPage.push(err);
          },
          onPageError: (msg) => {
            const err = {
              page: targetUrl,
              type: 'Uncaught Exception (pageerror)',
              message: msg,
              trigger: state.currentAction,
              time: new Date().toISOString()
            };
            pageErrors.push(err);
            pageErrorsThisPage.push(err);
          },
          onRequestActivity: (type, info) => {
            if (type === 'http_error') {
              const err = {
                page: targetUrl,
                type: `HTTP ${info.status}`,
                message: `استجابة برمز خطأ ${info.status} من الرابط: ${info.url}`,
                trigger: state.currentAction,
                time: new Date().toISOString()
              };
              pageErrors.push(err);
              pageErrorsThisPage.push(err);
            } else if (type === 'request_failed') {
              const err = {
                page: targetUrl,
                type: 'Request Failed',
                message: `فشل الطلب ${info.url}: ${info.errorText}`,
                trigger: state.currentAction,
                time: new Date().toISOString()
              };
              pageErrors.push(err);
              pageErrorsThisPage.push(err);
            }
          }
        });

        let navStatus = 200;
        let navError = null;
        let downloadReceived = null;

        // بند ٣: مراقبة التنزيل لتجنب إنذار كاذب في روابط التصدير إكسيل
        const downloadPromise = managed.page.waitForEvent('download', { timeout: 6000 }).catch(() => null);

        try {
          const resp = await managed.page.goto(targetUrl, { waitUntil: 'domcontentloaded', timeout: 12000 });
          navStatus = resp ? resp.status() : 200;
        } catch (e) {
          navError = e;
        }

        const isAbort = navError && (navError.message.includes('ERR_ABORTED') || navError.message.includes('Download is starting'));
        const isExportUrl = /[?&]export=xlsx\b/i.test(targetUrl) || /_export\.php\b/i.test(targetUrl);

        if (isAbort || isExportUrl) {
          downloadReceived = await downloadPromise;
        }

        try {
          if (downloadReceived) {
            const dlResult = await verifyAndHandleDownload(downloadReceived);
            if (dlResult.ok) {
              console.log(`[crawl] ✅ نجاح التصدير: ${targetUrl} — ${dlResult.message}`);
              crawledPages.push({
                url: targetUrl,
                slug,
                title: `تصدير إكسيل — ${dlResult.message}`,
                status: 200,
                clicks: 0,
                requestsCount: 1,
                slowestRequestMs: 0,
                slowest5: [],
                errorsCount: 0,
                exportInfo: dlResult.message,
                screenshots: {}
              });
              saveIncrementalReport();
              continue;
            } else {
              const err = {
                page: targetUrl,
                type: 'Export Error',
                message: dlResult.error,
                trigger: 'export-download',
                time: new Date().toISOString()
              };
              pageErrors.push(err);
              pageErrorsThisPage.push(err);
            }
          } else if (navError) {
            const err = {
              page: targetUrl,
              type: 'Navigation Timeout/Error',
              message: navError.message,
              trigger: 'page-goto',
              time: new Date().toISOString()
            };
            pageErrors.push(err);
            pageErrorsThisPage.push(err);
          }

          await inspectPageDom(managed.page, targetUrl);
          const shots = await takePageScreenshots(managed.page, slug);
          const clicks = await interactWithPage(managed.page, targetUrl, options.maxClicks);

          if (crawledPages.length < options.maxPages) {
            const internalLinks = await managed.page.evaluate((base) => {
              const anchors = Array.from(document.querySelectorAll('a[href]'));
              const res = [];
              for (const a of anchors) {
                try {
                  const u = new URL(a.href, base);
                  if (u.origin === new URL(base).origin && !u.pathname.includes('logout')) {
                    if (!u.pathname.match(/\.(css|js|png|jpg|jpeg|gif|svg|woff2?|ico|json)$/i)) {
                      res.push(u.origin + u.pathname + u.search);
                    }
                  }
                } catch (e) {}
              }
              return res;
            }, options.baseUrl).catch(() => []);

            for (const il of internalLinks) {
              if (crawledPages.length + queueA.length + queueB.length >= options.maxPages * 2) {
                break;
              }
              enqueueUrl(il);
            }
          }

          const stats = managed.getStats();
          crawledPages.push({
            url: targetUrl,
            slug,
            title: await managed.page.title().catch(() => slug),
            status: navStatus,
            clicks,
            requestsCount: stats.requestCount,
            slowestRequestMs: stats.slowestRequestMs,
            slowest5: stats.slowest5,
            errorsCount: pageErrorsThisPage.length,
            screenshots: shots
          });
        } finally {
          // بند ٢: إغلاق الصفحة في finally
          await managed.close();
        }

        // بند ٤: حفظ التقرير تدريجياً بعد كل صفحة
        saveIncrementalReport();
      }
    }
  }

  // قفل المتصفح
  await browser.close().catch(() => {});

  // فحص عدادات الداتابيز بعد الزحف
  console.log('[crawl] أخذ لقطة لعدادات الجداول الخمسة بعد الزحف...');
  dbCountsAfter = getDbCounts(options.wwwDir);
  console.log('[crawl] العدادات بعد:', JSON.stringify(dbCountsAfter));

  // مطابقة العدادات
  dbMatched = ['journal_entries', 'push_queue', 'sale_docs', 'notifications', 'activity_log'].every(
    key => dbCountsBefore[key] === dbCountsAfter[key]
  );
  console.log(`[crawl] حالة مطابقة الداتابيز: ${dbMatched ? '✅ متطابقة ١٠٠٪ (الحارس منع أي كتابة)' : '❌ غير متطابقة!'}`);

  // مدة الرن
  const durationSec = Math.round((Date.now() - startTime) / 1000);

  // بند ٤: حفظ التقارير النهائية
  normalExitCompleted = true;
  saveFinalReportsSync(true);
  reportSavedOnExit = true;

  console.log('[crawl] ====================================================');
  console.log(`[crawl] الملخص:`);
  console.log(`[crawl] - الصفحات المزحوفة: ${crawledPages.length}`);
  console.log(`[crawl] - إجمالي الضغطات: ${totalClicks}`);
  console.log(`[crawl] - الطلبات المقفولة بالحارس: ${blockedRequests.length}`);
  console.log(`[crawl] - مطابقة الداتابيز: ${dbMatched ? 'نعم (مطابقة بالكامل)' : 'لا'}`);
  console.log(`[crawl] - إجمالي الأخطاء المرصودة: ${pageErrors.length}`);
  console.log(`[crawl] - مدة الرن: ${durationSec} ثانية`);
  if (!credentials) {
    console.log('[crawl] 🔴 تنبيه: الرن الكامل (≥ 120 صفحة) مستني الحساب (CRAWL_USER و CRAWL_PASS).');
  }
  console.log('[crawl] ====================================================');
}

// ==========================================
// توليد HTML للتقرير بدون أي مكاتب خارجية
// ==========================================
function generateHtmlReport(data) {
  const meta = data.meta || {};
  const db = data.database_contract || {};
  const topErrors = data.top_30_errors || [];
  const pages = data.pages || [];
  const guard = data.guard || {};
  const guardSamples = guard.samples || [];
  const uncrawledPages = data.uncrawled_pages || [];

  const escapeHtml = (str) => {
    if (!str) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  };

  return `<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>تقرير زاحف فجالة ستور — بند ٨٠١ / ٨٠١b / ٨٠١c</title>
  <style>
    :root {
      --bg: #0f172a;
      --card-bg: #1e293b;
      --card-border: #334155;
      --text: #f8fafc;
      --text-muted: #94a3b8;
      --accent: #38bdf8;
      --pos: #10b981;
      --neg: #ef4444;
      --warn: #f59e0b;
      --font: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: var(--font);
      background: var(--bg);
      color: var(--text);
      line-height: 1.6;
      padding: 24px;
    }
    .container { max-width: 1400px; margin: 0 auto; }
    header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding-bottom: 20px;
      border-bottom: 1px solid var(--card-border);
      margin-bottom: 24px;
    }
    h1 { font-size: 24px; color: var(--accent); }
    .badge {
      display: inline-block;
      padding: 4px 12px;
      border-radius: 9999px;
      font-size: 13px;
      font-weight: 600;
    }
    .badge-ok { background: rgba(16, 185, 129, 0.2); color: var(--pos); border: 1px solid var(--pos); }
    .badge-warn { background: rgba(245, 158, 11, 0.2); color: var(--warn); border: 1px solid var(--warn); }
    .badge-neg { background: rgba(239, 68, 68, 0.2); color: var(--neg); border: 1px solid var(--neg); }

    .grid-kpis {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 16px;
      margin-bottom: 24px;
    }
    .kpi-card {
      background: var(--card-bg);
      border: 1px solid var(--card-border);
      border-radius: 8px;
      padding: 16px;
      text-align: center;
    }
    .kpi-title { font-size: 13px; color: var(--text-muted); margin-bottom: 8px; }
    .kpi-value { font-size: 26px; font-weight: bold; color: var(--text); }

    .section-card {
      background: var(--card-bg);
      border: 1px solid var(--card-border);
      border-radius: 8px;
      padding: 20px;
      margin-bottom: 24px;
    }
    .section-title {
      font-size: 18px;
      color: var(--accent);
      margin-bottom: 16px;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }

    table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 10px;
      font-size: 14px;
    }
    th, td {
      padding: 10px 14px;
      text-align: right;
      border-bottom: 1px solid var(--card-border);
    }
    th {
      background: rgba(255, 255, 255, 0.03);
      color: var(--text-muted);
      font-weight: 600;
    }
    tr:hover { background: rgba(255, 255, 255, 0.02); }
    .num { font-variant-numeric: tabular-nums; text-align: center; }

    .notice-box {
      background: rgba(56, 189, 248, 0.1);
      border: 1px solid var(--accent);
      border-radius: 6px;
      padding: 16px;
      margin-bottom: 24px;
      font-size: 14px;
    }
    a { color: var(--accent); text-decoration: none; }
    a:hover { text-decoration: underline; }
    code {
      background: rgba(0, 0, 0, 0.3);
      padding: 2px 6px;
      border-radius: 4px;
      font-family: monospace;
      font-size: 12px;
    }
  </style>
</head>
<body>
  <div class="container">
    <header>
      <div>
        <h1>تقرير زاحف فجالة ستور (بند ٨٠١ / ٨٠١b / ٨٠١c)</h1>
        <p style="color: var(--text-muted); font-size: 13px;">تاريخ التوليد: ${escapeHtml(meta.generatedAt)} | مدة الرن: ${meta.durationSeconds || 0} ثانية</p>
      </div>
      <div>
        ${meta.requiresAuthForFullRun
          ? '<span class="badge badge-warn">وضع شاشة الدخول (مستني الحساب للرن الكامل)</span>'
          : '<span class="badge badge-ok">رن كامل بمستخدم داخل</span>'}
      </div>
    </header>

    ${meta.requiresAuthForFullRun ? `
    <div class="notice-box">
      <strong>ℹ️ ملاحظة التشغيل:</strong>
      تم تشغيل الأداة واختبار كامل بنود الحارس واختبار الخطأ المقصود والتقاط صور شاشة الدخول والتأكد من مطابقة الجداول الخمسة.
      الرن الكامل على جميع الصفحات (بحد ٤٠٠ صفحة) يتطلب تمرير <code>CRAWL_USER</code> و <code>CRAWL_PASS</code> في متغيرات البيئة.
      أمر التشغيل: <code>CRAWL_USER=owner CRAWL_PASS=secret bash tests/local/crawl/crawl.sh</code>
    </div>` : ''}

    <div class="grid-kpis">
      <div class="kpi-card">
        <div class="kpi-title">الصفحات التي تم زحفها</div>
        <div class="kpi-value">${meta.totalPagesCrawled || 0}</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-title">صفحات لم تُفتح في site/</div>
        <div class="kpi-value" style="color: ${uncrawledPages.length > 0 ? 'var(--warn)' : 'var(--pos)'};">${uncrawledPages.length}</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-title">إجمالي الضغطات والتفاعلات</div>
        <div class="kpi-value">${meta.totalClicks || 0}</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-title">الطلبات المقفولة بالحارس</div>
        <div class="kpi-value" style="color: var(--pos);">${meta.totalBlockedRequests || 0}</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-title">حالة مطابقة الداتابيز</div>
        <div class="kpi-value" style="color: ${db.matched ? 'var(--pos)' : (db.matched === false ? 'var(--neg)' : 'var(--warn)')};">
          ${db.matched ? 'متطابقة ١٠٠٪' : (db.matched === false ? 'فروقات!' : 'جاري الفحص...')}
        </div>
      </div>
      <div class="kpi-card">
        <div class="kpi-title">إجمالي الأخطاء المرصودة</div>
        <div class="kpi-value" style="color: ${(meta.totalErrors || 0) > 0 ? 'var(--warn)' : 'var(--pos)'};">${meta.totalErrors || 0}</div>
      </div>
    </div>

    <!-- جدول عدادات الداتابيز -->
    <div class="section-card">
      <div class="section-title">
        <span>فحص عدادات الجداول الخمسة (عقد الأمان — قبل وبعد الزحف)</span>
        <span class="badge ${db.matched ? 'badge-ok' : (db.matched === false ? 'badge-neg' : 'badge-warn')}">${db.matched ? 'مطابقة ناجحة' : (db.matched === false ? 'فشل المطابقة' : 'قيد الفحص')}</span>
      </div>
      <table>
        <thead>
          <tr>
            <th>الجدول</th>
            <th class="num">العدد قبل الزحف</th>
            <th class="num">العدد بعد الزحف</th>
            <th class="num">الفرق</th>
            <th>الحالة</th>
          </tr>
        </thead>
        <tbody>
          ${['journal_entries', 'push_queue', 'sale_docs', 'notifications', 'activity_log'].map(t => {
            const b = (db.counts_before && db.counts_before[t] !== undefined && db.counts_before[t] !== null) ? db.counts_before[t] : 'غير موجود';
            const a = (db.counts_after && db.counts_after[t] !== undefined && db.counts_after[t] !== null) ? db.counts_after[t] : (db.counts_after ? 'غير موجود' : 'جاري الفحص...');
            const diff = (typeof b === 'number' && typeof a === 'number') ? (a - b) : 0;
            return `<tr>
              <td><code>${t}</code></td>
              <td class="num">${b}</td>
              <td class="num">${a}</td>
              <td class="num" style="color: ${diff === 0 ? 'var(--pos)' : 'var(--neg)'}; font-weight: bold;">${diff}</td>
              <td>${diff === 0 ? '<span class="badge badge-ok">مطابق (لم يُكتب شيء)</span>' : '<span class="badge badge-neg">تغير!</span>'}</td>
            </tr>`;
          }).join('')}
        </tbody>
      </table>
    </div>

    <!-- اختبار الحارس الذاتي واختبار الخطأ المقصود -->
    <div class="section-card">
      <div class="section-title">
        <span>اختبار الحارس الذاتي (بند ٢ في بريف ٨٠١c)</span>
        <span class="badge ${guard.self_test && guard.self_test.passed ? 'badge-ok' : 'badge-warn'}">
          ${guard.self_test && guard.self_test.passed ? 'اختبار الحارس نجح' : 'قيد الفحص'}
        </span>
      </div>
      <div style="background: rgba(0,0,0,0.25); padding: 12px; border-radius: 6px; font-size: 13px; line-height: 1.8;">
        <div>• فحص <code>invoices.php?tab=posted</code>: <strong>${guard.self_test && guard.self_test.tab_posted_allowed ? '✅ مسموح (عدّى بنجاح)' : '❌ مقفول'}</strong></div>
        <div>• فحص <code>x.php?action=post</code>: <strong>${guard.self_test && guard.self_test.action_post_blocked ? '✅ مقفول بالحارس بنجاح' : '❌ لم يُقفل'}</strong></div>
      </div>
    </div>

    <!-- اختبار الخطأ المقصود -->
    <div class="section-card">
      <div class="section-title">
        <span>اختبار الخطأ المقصود في صفحة مؤقتة (عقد الاكتمال #٤)</span>
        <span class="badge ${data.intentional_js_error_test && data.intentional_js_error_test.passed ? 'badge-ok' : 'badge-neg'}">
          ${data.intentional_js_error_test && data.intentional_js_error_test.passed ? 'تم التقاط الخطأ بنجاح' : 'لم يتم صيده'}
        </span>
      </div>
      <p style="font-size: 14px; color: var(--text-muted); margin-bottom: 8px;">
        تم إنشاء صفحة مؤقتة تحوي <code>&lt;script&gt;x.y()&lt;/script&gt;</code> وزيارتها والتأكد من تسجيل الخطأ ومسح الملف فوراً.
      </p>
      <div style="background: rgba(0,0,0,0.3); padding: 12px; border-radius: 4px; font-family: monospace; font-size: 13px; color: var(--warn);">
        الخطأ الملتقط: ${escapeHtml(data.intentional_js_error_test ? data.intentional_js_error_test.capturedError : 'قيد الانتظار')}
      </div>
    </div>

    <!-- عينات الطلبات المقفولة بالحارس -->
    <div class="section-card">
      <div class="section-title">
        <span>عينات من الطلبات المقفولة بحارس الأمان (الحارس شغال)</span>
        <span class="badge badge-ok">${guardSamples.length} عينة معروضة</span>
      </div>
      <table>
        <thead>
          <tr>
            <th>الطريقة</th>
            <th>الرابط</th>
            <th>السبب</th>
            <th>الصفحة المستدعية</th>
          </tr>
        </thead>
        <tbody>
          ${guardSamples.length > 0 ? guardSamples.map(s => `<tr>
            <td><span class="badge ${s.method === 'GET' ? 'badge-warn' : 'badge-neg'}">${escapeHtml(s.method)}</span></td>
            <td><code>${escapeHtml(s.url)}</code></td>
            <td>${escapeHtml(s.reason)}</td>
            <td><small>${escapeHtml(s.page || 'بدء الزحف')}</small></td>
          </tr>`).join('') : '<tr><td colspan="4" style="text-align: center;">لم يتم قفل طلبات</td></tr>'}
        </tbody>
      </table>
    </div>

    <!-- أول ٣٠ خطأ -->
    <div class="section-card">
      <div class="section-title">
        <span>أول ٣٠ خطأ تم رصدها في الموقع</span>
        <span class="badge ${topErrors.length > 0 ? 'badge-warn' : 'badge-ok'}">${topErrors.length} خطأ</span>
      </div>
      ${topErrors.length > 0 ? `
      <table>
        <thead>
          <tr>
            <th>#</th>
            <th>الصفحة</th>
            <th>نوع الخطأ</th>
            <th>الرسالة / التفاصيل</th>
            <th>الإجراء المسبب</th>
          </tr>
        </thead>
        <tbody>
          ${topErrors.map((err, i) => `<tr>
            <td class="num">${i + 1}</td>
            <td><small>${escapeHtml(err.page)}</small></td>
            <td><span class="badge badge-warn">${escapeHtml(err.type)}</span></td>
            <td><code>${escapeHtml(err.message)}</code></td>
            <td><small>${escapeHtml(err.trigger || 'تحميل')}</small></td>
          </tr>`).join('')}
        </tbody>
      </table>` : '<p style="color: var(--pos); font-size: 14px;">لم يتم رصد أخطاء غير معتادة في الفحص الحالي.</p>'}
    </div>

    <!-- جدول الصفحات والصور -->
    <div class="section-card">
      <div class="section-title">
        <span>جدول الصفحات التي تم فحصها</span>
        <span class="badge badge-ok">${pages.length} صفحة</span>
      </div>
      <table>
        <thead>
          <tr>
            <th>الصفحة</th>
            <th>العنوان</th>
            <th class="num">الحالة</th>
            <th class="num">الطلبات</th>
            <th class="num">الضغطات</th>
            <th class="num">عدد الأخطاء</th>
            <th class="num">أبطأ طلب (ms)</th>
            <th>الصور الملتقطة / حالة التصدير</th>
          </tr>
        </thead>
        <tbody>
          ${pages.map(p => `<tr>
            <td><code>${escapeHtml(p.slug)}</code></td>
            <td>${escapeHtml(p.title)}</td>
            <td class="num">${p.status}</td>
            <td class="num">${p.requestsCount !== undefined ? p.requestsCount : '—'}</td>
            <td class="num">${p.clicks}</td>
            <td class="num" style="color: ${p.errorsCount > 0 ? 'var(--warn)' : 'var(--pos)'};">${p.errorsCount}</td>
            <td class="num" title="${(p.slowest5 || []).map(s => `${s.url}: ${s.ms}ms`).join('\n')}">${p.slowestRequestMs} ms</td>
            <td>
              ${p.exportInfo ? `<span class="badge badge-ok">${escapeHtml(p.exportInfo)}</span>` : ''}
              ${p.screenshots && (p.screenshots.desktop_light || p.screenshots.mobile_light) ? `
                <a href="${escapeHtml(p.screenshots.desktop_light || '#')}" target="_blank">ديسكتوب فاتح</a> ·
                <a href="${escapeHtml(p.screenshots.desktop_dark || '#')}" target="_blank">ديسكتوب ليلي</a> ·
                <a href="${escapeHtml(p.screenshots.mobile_light || '#')}" target="_blank">موبايل فاتح</a> ·
                <a href="${escapeHtml(p.screenshots.mobile_dark || '#')}" target="_blank">موبايل ليلي</a>
              ` : (!p.exportInfo ? 'مفيش' : '')}
            </td>
          </tr>`).join('')}
        </tbody>
      </table>
    </div>

    <!-- صفحات site/*.php التي لم تُفتح بعد (بند ١ في بريف ٨٠١c) -->
    <div class="section-card">
      <div class="section-title">
        <span>صفحات في <code>site/*.php</code> لم تُفتح (${uncrawledPages.length} صفحة)</span>
        <span class="badge ${uncrawledPages.length === 0 ? 'badge-ok' : 'badge-warn'}">
          ${uncrawledPages.length === 0 ? 'تمت تغطية كل الصفحات' : 'متبقية'}
        </span>
      </div>
      <p style="font-size: 13px; color: var(--text-muted); margin-bottom: 12px;">
        مقارنة ملفات <code>site/*.php</code> (مش <code>_api</code> و <code>_lib</code> و <code>_core</code>) بالصفحات التي زارها الزاحف.
      </p>
      ${uncrawledPages.length > 0 ? `
      <div style="display: flex; flex-wrap: wrap; gap: 8px;">
        ${uncrawledPages.map(p => `<span style="background: rgba(255,255,255,0.05); border: 1px solid var(--card-border); padding: 4px 10px; border-radius: 4px; font-size: 13px; font-family: monospace;">${escapeHtml(p)}</span>`).join('')}
      </div>` : '<p style="color: var(--pos); font-size: 14px;">تم فتح كافة صفحات الموقع بنجاح!</p>'}
    </div>
  </div>
</body>
</html>`;
}

main().catch((err) => {
  console.error('[crawl] خطأ حرج أوقف الزاحف:', err);
  if (!reportSavedOnExit) {
    reportSavedOnExit = true;
    saveFinalReportsSync(false);
  }
  process.exit(1);
});
