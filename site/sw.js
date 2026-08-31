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

const VERSION = 'v23'; // v23: الأصل اللي مش في الكاش بيروح للشبكة صح | v22: الوضع الليلي | v21: الضغط على الإشعار بيعلّمه مقروء | v20: بترد على GET_VERSION
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
        if (hit) {
          // موجود في الكاش؟ ردّ بيه فوراً وحدّثه في الخلفية،
          // فأي ملف اترفع صح بيوصل في الفتحة اللي بعدها.
          e.waitUntil(
            fetch(req).then((res) => putIfGood(req, res)).catch(() => {})
          );
          return hit;
        }
        // 🔴 مش في الكاش؟ الشبكة **ومعاها الخطأ بتاعها**.
        //    الكود القديم كان `.catch(() => hit)` و`hit` هنا `undefined`،
        //    فـ`respondWith(undefined)` كان بيفشّل الطلب **نهائي** بدل ما
        //    يسيب المتصفح يتصرف. أي تعتيرة شبكة لحظية على الموبايل كانت
        //    بتخلي `fagala.css` مايوصلش والصفحة تطلع من غير أي ستايل.
        //    وده بيحصل بالذات بعد ما نزوّد `?v=` — النسخة الجديدة مش في
        //    الكاش أصلاً فمفيش حاجة ترجع لها.
        return fetch(req).then((res) => {
          putIfGood(req, res);
          return res;
        });
      })
    );
    return;
  }

  // أي حاجة تانية (صفحات PHP، بيانات): الشبكة مباشرة — بيانات لايف دايمًا
});

// =====================================================================
//  إشعارات الويب (Push Notifications)
// =====================================================================

self.addEventListener('push', (e) => {
  let data = {};
  if (e.data) {
    try {
      data = e.data.json();
    } catch (_) {
      try {
        data = { title: e.data.text() };
      } catch (__) {}
    }
  }

  const title = (data && data.title) ? data.title : 'إشعار جديد';
  const options = {
    body: (data && data.body) ? data.body : '',
    icon: './icon-192.png',
    badge: './favicon-32.png',
    data: {
      link: (data && data.link) ? data.link : './'
    }
  };

  e.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', (e) => {
  e.notification.close();
  const link = e.notification.data?.link;
  const target = (link && link !== './' && link !== '')
    ? 'notifications_read.php?go=' + encodeURIComponent(link)
    : './';
  const targetUrl = new URL(target, self.location.origin).href;

  e.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clientList) => {
      for (const client of clientList) {
        if (client.url && 'focus' in client) {
          if ('navigate' in client) {
            client.navigate(targetUrl);
          }
          return client.focus();
        }
      }
      if (clients.openWindow) {
        return clients.openWindow(targetUrl);
      }
    })
  );
});


// =====================================================================
//  الرد على سؤال «انت نسخة كام؟»
//
//  صفحة الإعدادات كانت بتقرا sw.js من السيرفر عشان تعرف النسخة — وده
//  بيقول اللي **على السيرفر** مش اللي **شغّال** على الجهاز. والفرق ده
//  وقّعنا: النسخة الشغالة كانت قديمة ومفيهاش معالج push، فالإشعارات
//  كانت بتتبعت وتتقبل من FCM ومحدش بيعرضها.
// =====================================================================
self.addEventListener('message', (e) => {
  if (e.data && e.data.type === 'GET_VERSION' && e.ports && e.ports[0]) {
    e.ports[0].postMessage({ version: VERSION, hasPush: true });
  }
});
