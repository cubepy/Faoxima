/* CubeVPN Downloader — service worker
 *
 * نسخه‌ی قبلی برای *هر* درخواستی اول سراغ کش می‌رفت و `index.html` را هم
 * موقعِ نصب کش کرده بود، بدون هیچ activate یا پاک‌سازی. نتیجه این بود که هر
 * بازدیدکننده‌ای که یک بار صفحه را باز کرده، تا ابد همان نسخه‌ی کش‌شده را
 * می‌دید — هر چقدر هم فایل جدید آپلود می‌شد. برای همین نامِ کش بالا رفته و
 * کش‌های قدیمی موقع فعال‌شدن پاک می‌شوند.
 */

var CACHE = 'cubevpn-downloader-v3';
var SHELL = ['./index.html', './manifest.json', './assets/cubevpn-logo.png'];

self.addEventListener('install', function (e) {
  self.skipWaiting();                      // منتظرِ بسته‌شدن تبِ قدیمی نمان
  e.waitUntil(
    caches.open(CACHE).then(function (cache) {
      return cache.addAll(SHELL).catch(function () { /* یک فایلِ غایب نباید نصب را بشکند */ });
    })
  );
});

self.addEventListener('activate', function (e) {
  e.waitUntil(
    caches.keys().then(function (keys) {
      return Promise.all(keys.map(function (k) {
        return k === CACHE ? null : caches.delete(k);   // کشِ نسخه‌های قبلی
      }));
    }).then(function () { return self.clients.claim(); })
  );
});

self.addEventListener('fetch', function (e) {
  var req = e.request;
  if (req.method !== 'GET') return;

  var url;
  try { url = new URL(req.url); } catch (err) { return; }
  if (url.origin !== self.location.origin) return;      // گیت‌هاب و بقیه، دستِ ما نه

  // دانلودِ اپلیکیشن و شمارنده هرگز نباید از کش بیایند یا داخل کش بروند:
  // فایل چند ده مگابایت است و درخواستِ Range را هم نباید دست بزنیم.
  if (url.pathname.indexOf('cubevpn.php') !== -1 || url.pathname.indexOf('/api/') !== -1) {
    return;                                             // بگذار مستقیم برود شبکه
  }

  // صفحه: اول شبکه، بعد کش. این‌طور به‌روزرسانی بلافاصله دیده می‌شود ولی
  // آفلاین هم صفحه باز می‌شود.
  var isPage = req.mode === 'navigate'
    || (req.headers.get('accept') || '').indexOf('text/html') !== -1;

  if (isPage) {
    e.respondWith(
      fetch(req).then(function (res) {
        var copy = res.clone();
        caches.open(CACHE).then(function (c) { c.put('./index.html', copy); });
        return res;
      }).catch(function () {
        return caches.match('./index.html').then(function (r) { return r || Response.error(); });
      })
    );
    return;
  }

  // بقیه‌ی فایل‌های ثابت: اول کش، بعد شبکه.
  e.respondWith(
    caches.match(req).then(function (hit) {
      return hit || fetch(req).then(function (res) {
        if (res && res.status === 200 && res.type === 'basic') {
          var copy = res.clone();
          caches.open(CACHE).then(function (c) { c.put(req, copy); });
        }
        return res;
      });
    })
  );
});
