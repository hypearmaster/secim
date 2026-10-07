// Oy sayacı: büyük renkli butonlar, her dokunuş +1, anlık toplam. Renkler config'den.
// Kural: kullanıcı verisi DOM'a textContent ile girer (innerHTML yok).
(function () {
  'use strict';

  var root = document.getElementById('counter');
  if (!root || !document.getElementById('vote-grid')) return;

  var cfg = JSON.parse(root.getAttribute('data-config'));
  var CSRF_HEADER = 'X-CSRF-Token';
  var csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

  var els = {
    grid: document.getElementById('vote-grid'),
    total: document.getElementById('vote-total'),
    last: document.getElementById('vote-last'),
    undo: document.getElementById('vote-undo'),
    reset: document.getElementById('vote-reset'),
    toast: document.getElementById('toast')
  };
  var counts = {};   // key -> sayı gösteren eleman
  var busy = false;
  var appliedVer = 0; // en son uygulanan sunucu anlık-görüntü zamanı (tallies.ver)

  // Önceki örnekten (sayfa yenilemesiz gezinme) kalan zamanlayıcı/dinleyici varsa temizle
  if (window.__counterPoll) { clearInterval(window.__counterPoll); window.__counterPoll = null; }
  if (window.__counterVis) { document.removeEventListener('visibilitychange', window.__counterVis); window.__counterVis = null; }

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined && text !== null) n.textContent = String(text);
    return n;
  }

  var toastTimer = null;
  function toast(msg, kind) {
    els.toast.textContent = msg;
    els.toast.className = 'toast is-visible' + (kind ? ' toast-' + kind : '');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { els.toast.className = 'toast'; }, 2500);
  }

  function goLogin() { window.location.href = cfg.login; }

  // Hex rengin açık mı olduğunu kestirir (beyaz/sarı gibi butonda yazı koyu olsun)
  function isLight(hex) {
    var m = /^#?([0-9a-f]{6})$/i.exec(hex || '');
    if (!m) return false;
    var n = parseInt(m[1], 16);
    var r = (n >> 16) & 255, g = (n >> 8) & 255, b = n & 255;
    return (0.299 * r + 0.587 * g + 0.114 * b) > 186;
  }

  function post(action, option) {
    var data = { action: action };
    if (option) data.option = option;
    if (cfg.eventId != null) data.event_id = cfg.eventId;
    var headers = { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' };
    headers[CSRF_HEADER] = csrf;
    return fetch(cfg.api, { method: 'POST', body: new URLSearchParams(data), headers: headers, credentials: 'same-origin' })
      .then(function (r) {
        if (r.status === 401) { goLogin(); throw new Error('auth'); }
        return r.json().then(function (j) { j.httpStatus = r.status; return j; });
      });
  }

  // Butonları (ilk kez) çiz
  function build(options) {
    els.grid.textContent = '';
    counts = {};
    options.forEach(function (o) {
      var btn = el('button', 'vote-btn' + (isLight(o.color) ? ' is-light' : ''));
      btn.type = 'button';
      btn.style.setProperty('--vote', o.color);
      btn.setAttribute('data-key', o.key);
      btn.setAttribute('aria-label', o.label + ' +1');
      btn.appendChild(el('span', 'vote-btn-label', o.label));
      var c = el('span', 'vote-btn-count', o.count);
      counts[o.key] = c;
      btn.appendChild(c);
      btn.appendChild(el('span', 'vote-btn-hint', 'dokun +1'));
      btn.addEventListener('click', function () { add(o.key, btn); });
      els.grid.appendChild(btn);
    });
  }

  function paint(t, bumpKey) {
    if (!t) return;
    // Buton kümesi değiştiyse (config güncellendi) yeniden çiz
    var keys = t.options.map(function (o) { return o.key; }).join(',');
    if (keys !== Object.keys(counts).join(',')) { build(t.options); }
    t.options.forEach(function (o) {
      var c = counts[o.key];
      if (!c) return;
      if (c.textContent !== String(o.count)) {
        c.textContent = o.count;
        if (o.key === bumpKey) { c.classList.remove('bump'); void c.offsetWidth; c.classList.add('bump'); }
      }
    });
    els.total.textContent = t.total;
    if (t.last) {
      els.last.textContent = 'Son: ' + t.last.label + (t.last.time ? ' · ' + t.last.time : '') + (t.last.user ? ' · ' + t.last.user : '');
    } else {
      els.last.textContent = 'Henüz sayım yok';
    }
  }

  // Yanıtı uygula: etkinlik değiştiyse yenile; daha eski anlık görüntüyü (tallies.ver) yok say; sonra çiz.
  // ver SUNUCU zamanıdır: hangi yanıt gelirse gelsin en güncel anlık görüntü kazanır (add/undo sırası karışsa da).
  function applyRes(res, bumpKey) {
    if (res && res.event_changed) { toast('Aktif liste değişti, yenileniyor…', 'warning'); setTimeout(function () { window.location.reload(); }, 800); return; }
    if (res && res.event_id != null && cfg.eventId != null && res.event_id !== cfg.eventId) { window.location.reload(); return; }
    if (!res || !res.tallies) return;
    var ver = res.tallies.ver;
    if (typeof ver === 'number') { if (ver < appliedVer) return; appliedVer = ver; }
    paint(res.tallies, bumpKey);
  }

  function add(key, btn) {
    if (navigator.vibrate) navigator.vibrate(20);
    // İyimser: hemen +1 göster, sunucudan kesin değerle düzelt
    var c = counts[key];
    if (c) { c.textContent = String((parseInt(c.textContent, 10) || 0) + 1); c.classList.remove('bump'); void c.offsetWidth; c.classList.add('bump'); }
    post('add', key)
      .then(function (res) {
        if (!res.ok && !res.event_changed) toast(res.error || 'Sayılamadı', 'error');
        applyRes(res, key);
      })
      .catch(function (err) { if (err.message !== 'auth') { toast('Bağlantı yok, tekrar deneyin', 'error'); refresh(); } });
  }

  els.undo.addEventListener('click', function () {
    if (busy) return;
    busy = true;
    els.undo.disabled = true;
    post('undo', null)
      .then(function (res) {
        if (res.ok) toast('Geri alındı: ' + (res.undone || ''), 'success');
        else if (!res.event_changed) toast(res.error || 'Geri alınamadı', res.httpStatus === 409 ? 'warning' : 'error');
        applyRes(res);
      })
      .catch(function (err) { if (err.message !== 'auth') toast('Bağlantı yok, tekrar deneyin', 'error'); })
      .finally(function () { busy = false; els.undo.disabled = false; });
  });

  if (els.reset) {
    els.reset.addEventListener('click', function () {
      if (!window.confirm('Oy sayacı sıfırlansın mı? Tüm sayımlar silinir, bu geri alınamaz.')) return;
        post('reset', null)
        .then(function (res) {
          if (res.ok) toast('Sayaç sıfırlandı');
          else if (!res.event_changed) toast(res.error || 'Sıfırlanamadı', 'error');
          applyRes(res);
        })
        .catch(function (err) { if (err.message !== 'auth') toast('Bağlantı yok, tekrar deneyin', 'error'); });
    });
  }

  function refresh() {
    if (!document.body.contains(root)) { stopPoll(); return; } // sayfadan ayrıldık (yenilemesiz gezinme)
    fetch(cfg.api, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { if (r.status === 401) { goLogin(); throw new Error('auth'); } return r.json(); })
      .then(function (res) { if (res.ok) applyRes(res); })
      .catch(function () { /* sessiz: bir sonraki poll dener */ });
  }

  // Başka yöneticiler de sayıyor olabilir: düzenli tazele + sekmeye dönünce.
  // Zamanlayıcı window'da: yenilemesiz gezinmede eski sayfanın poll'u birikmesin.
  function startPoll() { stopPoll(); window.__counterPoll = setInterval(refresh, 4000); }
  function stopPoll() { if (window.__counterPoll) clearInterval(window.__counterPoll); window.__counterPoll = null; }
  window.__counterVis = function () {
    if (!document.body.contains(root)) { stopPoll(); return; }
    if (document.visibilityState === 'visible') { refresh(); startPoll(); } else { stopPoll(); }
  };
  document.addEventListener('visibilitychange', window.__counterVis);

  build(cfg.tallies.options || []);
  paint(cfg.tallies);
  startPoll();
})();
