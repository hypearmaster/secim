// Sayfa yenilemeden gezinme: menü linkleri ve formlar arka planda yüklenir, sadece sayfa
// gövdesi değişir; adres çubuğu hep uygulamanın kökünde kalır (gencderi.com/secim/).
// Geri tuşu uygulama içinde çalışır; F5 bulunduğun sayfayı yeniden açar.
// Dosya indirmeleri (Excel) ve data-no-nav işaretli link/formlar normal çalışır.
(function () {
  'use strict';
  if (window.secimNav) return; // sayfa değişiminde yeniden çalıştırılırsa tekrar kurma

  var base = document.body.getAttribute('data-base') || '';
  var root = base + '/';
  var KEY = 'secim_page';
  var busy = false;

  function store(path) { try { sessionStorage.setItem(KEY, path); } catch (e) { /* gizli sekme */ } }
  function stored() { try { return sessionStorage.getItem(KEY); } catch (e) { return null; } }

  // Uygulama içi mi? (aynı köken, base altında, indirme değil)
  function internal(url) {
    if (url.origin !== location.origin) return false;
    if (url.pathname.indexOf(base + '/') !== 0 && url.pathname !== base) return false;
    if (/\/(api|assets)\//.test(url.pathname)) return false;
    if (url.searchParams.has('download')) return false;
    return true;
  }

  // Görünen yol: adres çubuğuna ve geçmişe yazılan değil, F5 için saklanan gerçek sayfa
  function pagePath(url) { return url.pathname + url.search; }

  function progress(on) {
    var bar = document.getElementById('nav-progress');
    if (!bar) {
      bar = document.createElement('div');
      bar.id = 'nav-progress';
      document.documentElement.appendChild(bar);
    }
    bar.className = on ? 'is-on' : '';
  }

  // Yeni belgenin başlığını, CSRF etiketini, sayfa CSS'lerini ve gövdesini yerleştir; scriptleri çalıştır
  function swap(html, finalUrl) {
    var doc = new DOMParser().parseFromString(html, 'text/html');
    document.title = doc.title;
    var oldMeta = document.querySelector('meta[name="csrf-token"]');
    var newMeta = doc.querySelector('meta[name="csrf-token"]');
    if (oldMeta && newMeta) oldMeta.setAttribute('content', newMeta.getAttribute('content'));

    // Sayfaya özel stil dosyaları: yenileri ekle, kullanılmayanları kaldır
    var want = Array.prototype.map.call(doc.querySelectorAll('link[rel="stylesheet"]'), function (l) { return l.getAttribute('href'); });
    Array.prototype.forEach.call(document.querySelectorAll('link[rel="stylesheet"]'), function (l) {
      if (want.indexOf(l.getAttribute('href')) === -1) l.remove();
    });
    want.forEach(function (href) {
      if (!document.querySelector('link[rel="stylesheet"][href="' + href + '"]')) {
        var l = document.createElement('link');
        l.rel = 'stylesheet';
        l.href = href;
        document.head.appendChild(l);
      }
    });

    document.body.innerHTML = doc.body.innerHTML;
    Array.prototype.forEach.call(doc.body.attributes, function (a) { document.body.setAttribute(a.name, a.value); });

    // innerHTML ile gelen scriptler çalışmaz: yeniden oluştur (sıra korunur)
    Array.prototype.forEach.call(document.body.querySelectorAll('script'), function (old) {
      if (old.src && /\/nav\.js(\?|$)/.test(old.src)) return; // kendimizi tekrar yükleme
      var s = document.createElement('script');
      Array.prototype.forEach.call(old.attributes, function (a) { s.setAttribute(a.name, a.value); });
      s.async = false;
      if (!old.src) s.textContent = old.textContent;
      old.replaceWith(s);
    });

    store(pagePath(finalUrl));
    window.scrollTo(0, 0);
    var focus = document.querySelector('main h1, main h2, main');
    if (focus) { focus.setAttribute('tabindex', '-1'); focus.focus({ preventScroll: true }); }
  }

  function load(url, opts, push) {
    if (busy) return Promise.resolve();
    busy = true;
    progress(true);
    opts = opts || {};
    opts.credentials = 'same-origin';
    opts.headers = Object.assign({ 'X-Nav': '1' }, opts.headers || {});
    return fetch(url.toString(), opts)
      .then(function (r) {
        var type = r.headers.get('Content-Type') || '';
        if (type.indexOf('text/html') === -1) {
          // HTML değil (beklenmedik indirme vb.): normal yoldan aç
          window.location.href = url.toString();
          return;
        }
        return r.text().then(function (html) {
          var finalUrl = new URL(r.url);
          swap(html, finalUrl);
          if (push) history.pushState({ page: pagePath(finalUrl) }, '', root);
          else history.replaceState({ page: pagePath(finalUrl) }, '', root);
        });
      })
      .catch(function () {
        // Ağ hatası: kullanıcıyı ortada bırakma, normal sayfa yüklemesi dene
        window.location.href = url.toString();
      })
      .finally(function () { busy = false; progress(false); });
  }

  // Link tıklamaları
  document.addEventListener('click', function (e) {
    if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    var a = e.target.closest('a[href]');
    if (!a || a.target || a.hasAttribute('download') || a.closest('[data-no-nav]')) return;
    var url = new URL(a.href, location.href);
    if (!internal(url) || (url.hash && url.pathname === location.pathname)) return;
    e.preventDefault();
    load(url, { method: 'GET' }, true);
  });

  // Form gönderimleri (inline onsubmit="return confirm(...)" önce çalışır; iptal ederse buraya gelmez)
  document.addEventListener('submit', function (e) {
    if (e.defaultPrevented) return;
    var form = e.target;
    if (form.closest('[data-no-nav]')) return;
    var btn = e.submitter || null;
    var method = ((btn && btn.getAttribute('formmethod')) || form.getAttribute('method') || 'GET').toUpperCase();
    var url = new URL((btn && btn.getAttribute('formaction')) || form.getAttribute('action') || stored() || location.href, location.href);
    if (!internal(url)) return;
    var data = new FormData(form);
    if (btn && btn.name) data.append(btn.name, btn.value);
    if (method === 'GET') {
      url.search = new URLSearchParams(data).toString();
      if (url.searchParams.has('download')) return; // Excel indir: tarayıcı halletsin
      e.preventDefault();
      load(url, { method: 'GET' }, true);
    } else {
      e.preventDefault();
      if (btn) btn.disabled = true;
      load(url, { method: 'POST', body: data }, true).finally(function () { if (btn) btn.disabled = false; });
    }
  });

  // Geri / ileri
  window.addEventListener('popstate', function (e) {
    var page = (e.state && e.state.page) || stored();
    if (page) load(new URL(page, location.origin), { method: 'GET' }, false);
  });

  window.secimNav = {
    reload: function () {
      var page = stored();
      return load(new URL(page || location.href, location.origin), { method: 'GET' }, false);
    }
  };

  // İlk açılış: gerçek sayfayı sakla, adresi köke çek. F5 (yenileme) ise son sayfayı geri aç
  // (kök adres sunucuda ana sayfaya yönlendirildiği için son sayfa sessionStorage'dan gelir).
  var current = new URL(location.href);
  var last = stored();
  var nav = (performance.getEntriesByType && performance.getEntriesByType('navigation')[0]) || {};
  var isReload = nav.type === 'reload';
  if (document.querySelector('.topbar') && isReload && last && last !== pagePath(current)) {
    load(new URL(last, location.origin), { method: 'GET' }, false);
  } else {
    store(pagePath(current));
    history.replaceState({ page: pagePath(current) }, '', root);
  }
})();
