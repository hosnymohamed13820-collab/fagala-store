// Service Worker — نظام الفجالة ستور
// بسيط عن قصد: بيخلي الموقع "قابل للتثبيت" كتطبيق، ويكاش الأصول الثابتة بس.
// النظام مالي وبياناته لايف، فمابنكاشش الصفحات نفسها عشان مايظهرش بيانات قديمة.
//
// مهم: لما تغيّر الأيقونات أو أي أصل ثابت، **زوّد VERSION تحت**.
// من غير كده المتصفح هيفضل يعرض القديم مهما مسحت الكاش من الإعدادات.

// =====================================================================
//  v11 — إصلاح: مانكاشش أي رد فاشل
// =====================================================================
//  اللي حصل يوم 2026-08-09:
//    فولدر assets/ مكانش مرفوع على السيرفر. كل طلب لـ
//    tabler-subset.woff2 و fagala.css كان بيرجّع **صفحة 404 من
//    هوستنجر** (HTML). والنسخة القديمة هنا كانت بتعمل:
//
//        c.put(req, copy)      ← من غير ما تبص على res.status
//
//    يعني كاشت صفحة الـ404 على إنها الخط والستايل. وبما إن الأصول
//    بتتقري "من الكاش الأول"، فضلت تقدّم الـHTML الغلط ده حتى بعد
//    ما الملفات الصح اترفعت. الأيقونات فضلت فاضية والستايل باظ،
//    ومفيش ريفريش عادي بيصلّحها.
//
//  الإصلاح:
//    1) مانحطش حاجة في الكاش غير لو الرد 200 وسليم فعلاً
//    2) لو الكاش فيه حاجة باظت، مانقدّمهاش — نروح للشبكة
//    3) VERSION اتزوّد لـ v11، فالكاش القديم المسموم بيتمسح لوحده
//       على كل جهاز أول ما يفتح النظام. مفيش تنضيف يدوي.
// =====================================================================

const VERSION = 'v17'; // v16: acctree.js نسخة الليستين — كسر كاش ?v=2 في treasury.php و custody.php
const CACHE = 'fagala-' + VERSION;

const ASSETS = [
  './manifest.json',
  './icon-192.png',
  './icon-512.png',
  './icon-maskable.png',
  './apple-touch-icon.png',
  './favicon-32.png',
  './assets/app.js',
  './assets/xtable.js',
  './assets/acctree.js',
  './assets/fagala.css',
  './assets/barcode_scanner.js',
  './assets/tabler-subset.css',
  './assets/tabler-subset.woff2'
];

// رد يستاهل يتكاش؟
//   ok/200      = مش 404 ولا 500 ولا صفحة خطأ
//   type basic  = من نفس الدومين (مش رد مبهم من CDN)
function isGood(res) {
  return res && res.ok && res.status === 200 &&
         (res.type === 'basic' || res.type === 'default');
}

// حفظ آمن — أي رد وحش بيتتجاهل بدل ما يتخزّن ويسمّم الكاش
function putIfGood(req, res) {
  if (!isGood(res)) return;
  const copy = res.clone();
  caches.open(CACHE).then((c) => c.put(req, copy)).catch(() => {});
}

self.addEventListener('install', (e) => {
  e.waitUntil(
    caches.open(CACHE).then((c) =>
      // كل ملف لوحده مش addAll: addAll بتفشل كلها لو ملف واحد باظ،
      // وكمان بتكاش أي رد حتى لو 404. كده الملف الناقص بيتساب
      // للشبكة والباقي بيتكاش عادي.
      Promise.all(ASSETS.map((url) =>
        fetch(url, { cache: 'reload' })
          .then((res) => { if (isGood(res)) return c.put(url, res); })
          .catch(() => {})
      ))
    ).catch(() => {})
  );
  self.skipWaiting();
});

self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)))
    )
  );
  self.clients.claim();
});

self.addEventListener('fetch', (e) => {
  const req = e.request;
  const url = new URL(req.url);

  const isAsset = /\.(png|jpg|jpeg|svg|ico|js|css|json|woff2?)$/i.test(url.pathname);
  if (req.method === 'GET' && isAsset) {
    // الأيقونات والمانيفست: الشبكة الأول.
    // (cache-first كان بيخلي الأيقونة القديمة تعيش للأبد بعد أي تحديث)
    const isIcon = /(icon|favicon|apple-touch|manifest)/i.test(url.pathname);

    if (isIcon) {
      e.respondWith(
        fetch(req).then((res) => {
          putIfGood(req, res);
          return res;
        }).catch(() => caches.match(req))
      );
      return;
    }

    // باقي الأصول: من الكاش الأول (أسرع)، وبيتحدّث في الخلفية
    e.respondWith(
      caches.match(req).then((hit) => {
        const net = fetch(req).then((res) => {
          putIfGood(req, res);
          return res;
        }).catch(() => hit);
        // hit موجود؟ استخدمه. ومهما حصل الشبكة شغالة في الخلفية
        // فأي ملف اترفع صح بيوصل في الفتحة اللي بعدها.
        return hit || net;
      })
    );
    return;
  }

  // أي حاجة تانية (صفحات PHP، بيانات): الشبكة مباشرة — بيانات لايف دايمًا
});
