// Hediye ekranı: arama, filtre, VERDİK / Geri al, elle kişi ekle.
// Kural: kişi verisi DOM'a sadece textContent ile girer (innerHTML yok).
// Canlı yenileme: sayfa/liste baştan çizilmez; 10 sn'de bir arka planda veri çekilir (sekme görünürken),
// sadece değişen kartlar/sayılar yerinde güncellenir (live.js).
(function () {
  'use strict';

  var root = document.getElementById('gift');
  if (!root || !document.getElementById('list')) return;

  var cfg = JSON.parse(root.getAttribute('data-config'));
  var CSRF_HEADER = 'X-CSRF-Token';
  var csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

  var els = {
    q: document.getElementById('q'),
    region: document.getElementById('gift-region'),
    list: document.getElementById('list'),
    empty: document.getElementById('empty'),
    more: document.getElementById('more'),
    info: document.getElementById('result-info'),
    toast: document.getElementById('toast'),
    given: document.getElementById('cnt-given'),
    total: document.getElementById('cnt-total'),
    bar: document.getElementById('cnt-bar'),
    addOpen: document.getElementById('gift-add-open'),
    dialog: document.getElementById('gift-add'),
    addForm: document.getElementById('gift-add-form'),
    addName: document.getElementById('gift-add-name'),
    addNote: document.getElementById('gift-add-note'),
    addCancel: document.getElementById('gift-add-cancel'),
    addSave: document.getElementById('gift-add-save')
  };

  var POLL_MS = 10000;
  var state = { q: '', status: 'all', region: '', page: 1 };
  var controller = null;
  var debounce = null;
  var loading = false;   // liste (baştan) yükleniyor: arka plan yenilemesi beklesin
  var inflight = 0;      // devam eden POST sayısı
  var opVer = 0;         // her POST başında/sonunda artar: eski anlık görüntü yeni işlemi ezmesin
  var queryVer = 0;      // arama/filtre her değiştiğinde artar
  var pollCtl = null;

  /* ---------- yardımcılar ---------- */

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
    toastTimer = setTimeout(function () { els.toast.className = 'toast'; }, 3000);
  }

  function setStats(s) {
    if (!s) return;
    els.given.textContent = s.given;
    els.total.textContent = s.total;
    els.bar.style.width = (s.total ? Math.round(s.given / s.total * 100) : 0) + '%';
  }

  function goLogin() { window.location.href = cfg.login; }

  // İşlem sayacı: devam eden bir işlem varken gelen arka plan yanıtı uygulanmaz
  function post(data) {
    inflight++;
    opVer++;
    return rawPost(data).finally(function () { inflight--; opVer++; });
  }

  function rawPost(data) {
    var headers = { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' };
    headers[CSRF_HEADER] = csrf;
    return fetch(cfg.api, { method: 'POST', body: new URLSearchParams(data), headers: headers, credentials: 'same-origin' })
      .then(function (r) {
        if (r.status === 401) { goLogin(); throw new Error('auth'); }
        return r.json().then(function (j) { j.httpStatus = r.status; return j; });
      });
  }

  /* ---------- kart ---------- */

  function card(g) {
    var c = el('article', 'g-card' + (g.given ? ' is-given' : ''));
    c.setAttribute('data-id', g.id);

    var main = el('div', 'g-main');
    main.appendChild(el('h3', 'g-name', g.name));
    if (g.note) main.appendChild(el('div', 'g-note', g.note));
    // Onay listesindeki kayıttan (tam isim eşleşmesi): "Kemer / Beldibi Mah." ve Transfer çipi (yetkiliye)
    // Satıra dokununca kart yerinde açılır: tam adres + Yol tarifi (adres yoksa "Adres kayıtlı değil")
    var places = (g.addresses || []).map(function (a) { return { label: '', address: a }; });
    var whereText = [g.region, g.neighborhood].filter(Boolean).join(' / ') || (places.length ? 'Adres' : 'Konum bilgisi yok');
    var pl = window.secimLive.place({
      scope: 'hediye', id: g.id, text: whereText, title: whereText,
      lines: [['Not', g.note]], places: places
    });
    main.appendChild(pl.btn);
    main.appendChild(pl.panel);
    if (g.transfer) main.appendChild(el('span', 'badge g-transfer badge-' + g.transfer.color, 'Transfer: ' + g.transfer.label));
    var regionLabel = el('label', 'g-region', 'Bölge');
    var regionSelect = el('select', 'input');
    regionSelect.setAttribute('aria-label', g.name + ' bölgesi');
    var unknown = el('option', null, 'Bölgesi belirsiz');
    unknown.value = '';
    unknown.disabled = true;
    regionSelect.appendChild(unknown);
    var regions = (cfg.regions || []).slice();
    if (g.region && regions.indexOf(g.region) === -1) regions.push(g.region);
    regions.forEach(function (region) {
      var option = el('option', null, region);
      option.value = region;
      regionSelect.appendChild(option);
    });
    regionSelect.value = g.region || '';
    regionSelect.addEventListener('change', function () {
      regionSelect.disabled = true;
      post({ action: 'region', id: g.id, region: regionSelect.value, event_id: cfg.eventId })
        .then(function (res) {
          if (!res.ok) { toast(res.error || 'Bölge kaydedilemedi', 'error'); regionSelect.value = g.region || ''; }
          else { toast('Bölge kaydedildi', 'success'); load(true); }
        })
        .catch(function (err) { if (err.message !== 'auth') toast('Bağlantı yok, tekrar deneyin', 'error'); regionSelect.value = g.region || ''; })
        .finally(function () { regionSelect.disabled = false; });
    });
    regionLabel.appendChild(regionSelect);
    main.appendChild(regionLabel);
    c.appendChild(main);

    var act = el('div', 'g-action');
    if (!g.given) {
      var b = el('button', 'btn btn-verdik', 'VERDİK');
      b.type = 'button';
      b.setAttribute('aria-label', g.name + ' için hediye verildi olarak işaretle');
      b.addEventListener('click', function () { mark(g, 'mark', b); });
      act.appendChild(b);
    } else {
      var done = el('div', 'g-done');
      done.appendChild(el('strong', null, '✓ Verildi' + (g.given_time ? ' ' + g.given_time : '')));
      if (g.given_by_name) done.appendChild(el('span', 'g-by', g.given_by_name));
      act.appendChild(done);
      var u = el('button', 'btn btn-sm btn-ghost g-undo', 'Geri al');
      u.type = 'button';
      u.addEventListener('click', function () {
        if (window.confirm(g.name + ' için hediye işareti geri alınsın mı?')) mark(g, 'unmark', u);
      });
      act.appendChild(u);
    }
    c.appendChild(act);
    return window.secimLive.sign(c, g); // arka plan yenilemesi değişikliği bu imzayla anlar
  }

  function replaceCard(g) {
    var old = els.list.querySelector('.g-card[data-id="' + g.id + '"]');
    if (!old) return;
    var fresh = card(g);
    fresh.__touched = true; // bu oturumda işlem yapılan kart: arka plan yenilemesi silmesin
    old.replaceWith(fresh);
  }

  /* ---------- işlemler ---------- */

  function mark(g, action, btn) {
    btn.disabled = true;
    btn.classList.add('is-loading');
    post({ id: g.id, action: action })
      .then(function (res) {
        setStats(res.stats);
        if (res.gift) replaceCard(res.gift);
        if (res.ok) {
          if (action === 'mark') {
            if (navigator.vibrate) navigator.vibrate(30);
            toast('✓ ' + res.gift.name + ' verildi', 'success');
          } else {
            toast('İşaret geri alındı');
          }
        } else {
          toast(res.error || 'İşlem yapılamadı', res.httpStatus === 409 ? 'warning' : 'error');
          if (!res.gift) { btn.disabled = false; btn.classList.remove('is-loading'); }
        }
      })
      .catch(function (err) {
        if (err.message === 'auth') return;
        btn.disabled = false;
        btn.classList.remove('is-loading');
        toast('Bağlantı yok, tekrar deneyin', 'error');
      });
  }

  /* ---------- kişi ekle ---------- */

  function openAdd() {
    els.addName.value = '';
    els.addNote.value = '';
    if (els.dialog.showModal) els.dialog.showModal(); else els.dialog.setAttribute('open', '');
    els.addName.focus();
  }
  function closeAdd() {
    if (els.dialog.close) els.dialog.close(); else els.dialog.removeAttribute('open');
  }

  if (els.addOpen) els.addOpen.addEventListener('click', openAdd);
  if (els.addCancel) els.addCancel.addEventListener('click', closeAdd);
  if (els.addForm) els.addForm.addEventListener('submit', function (e) {
    e.preventDefault();
    var name = els.addName.value.trim();
    if (!name) { els.addName.focus(); return; }
    els.addSave.disabled = true;
    post({ action: 'add', name: name, note: els.addNote.value.trim() })
      .then(function (res) {
        setStats(res.stats);
        if (res.ok) {
          closeAdd();
          toast('Eklendi: ' + res.gift.name, 'success');
          load(true);
        } else {
          toast(res.error || 'Eklenemedi', 'error');
        }
      })
      .catch(function (err) { if (err.message !== 'auth') toast('Bağlantı yok, tekrar deneyin', 'error'); })
      .finally(function () { els.addSave.disabled = false; });
  });

  /* ---------- arama / liste ---------- */

  function load(reset) {
    if (reset) { state.page = 1; queryVer++; }
    if (pollCtl) pollCtl.abort();
    if (controller) controller.abort();
    var me = controller = new AbortController();
    loading = true;
    var params = new URLSearchParams({ q: state.q, status: state.status, region: state.region, page: state.page });
    root.classList.add('is-loading');
    els.more.disabled = true;

    fetch(cfg.api + '?' + params.toString(), {
      signal: controller.signal, credentials: 'same-origin', headers: { 'Accept': 'application/json' }
    })
      .then(function (r) {
        if (r.status === 401) { goLogin(); throw new Error('auth'); }
        return r.json();
      })
      .then(function (res) {
        if (!res.ok) { toast(res.error || 'Liste alınamadı', 'error'); return; }
        if (reset) els.list.textContent = '';
        var frag = document.createDocumentFragment();
        res.items.forEach(function (g) {
          // "Daha fazla": arka plan yenilemesi araya kart eklediyse aynı kart iki kez çizilmesin
          if (!reset && els.list.querySelector('.g-card[data-id="' + g.id + '"]')) return;
          frag.appendChild(card(g));
        });
        els.list.appendChild(frag);
        els.empty.hidden = res.total > 0;
        els.more.hidden = !res.has_more;
        els.info.textContent = res.total + ' kayıt';
        setStats(res.stats);
      })
      .catch(function (err) {
        if (err.name === 'AbortError' || err.message === 'auth') return;
        toast('Bağlantı yok, tekrar deneyin', 'error');
      })
      .finally(function () {
        if (controller !== me) return; // araya yeni bir yükleme girdi; o kendi bitişini yönetir
        loading = false;
        root.classList.remove('is-loading');
        els.more.disabled = false;
      });
  }

  /* ---------- arka plan yenilemesi (sayfa yenilenmez) ---------- */

  function poll() {
    if (loading || inflight > 0 || pollCtl) return;
    var q0 = queryVer, o0 = opVer;
    var pages = Math.min(5, Math.max(1, state.page));
    var me = pollCtl = new AbortController();
    var params = new URLSearchParams({ q: state.q, status: state.status, region: state.region, page: 1, upto: pages });
    fetch(cfg.api + '?' + params.toString(), {
      signal: me.signal, credentials: 'same-origin', cache: 'no-store', headers: { 'Accept': 'application/json' }
    })
      .then(function (r) {
        if (r.status === 401) { goLogin(); throw new Error('auth'); }
        return r.json();
      })
      .then(function (res) {
        // Bu arada arama/filtre değiştiyse ya da bir işlem yapıldıysa bu anlık görüntü eskidir: at
        if (!res.ok || q0 !== queryVer || o0 !== opVer || inflight > 0 || loading) return;
        if (res.event_id != null && cfg.eventId != null && res.event_id !== cfg.eventId) {
          window.secimNav ? window.secimNav.reload() : window.location.reload(); // aktif liste değişti: tam yenile
          return;
        }
        setStats(res.stats);
        var partial = state.page > pages; // 5 sayfadan fazlası yüklü: sadece var olan kartları güncelle
        window.secimLive.reconcile(els.list, res.items, { build: card, sel: '.g-card', patchOnly: partial, keep: function (n) { return n.__touched; } });
        if (!partial) {
          els.empty.hidden = res.total > 0;
          els.more.hidden = !res.has_more;
          els.info.textContent = res.total + ' kayıt';
        }
      })
      .catch(function () { /* sessiz: bir sonraki turda tekrar dener */ })
      .finally(function () { if (pollCtl === me) pollCtl = null; });
  }

  els.region.addEventListener('change', function () { state.region = els.region.value; load(true); });
  els.q.addEventListener('input', function () {
    clearTimeout(debounce);
    debounce = setTimeout(function () {
      state.q = els.q.value.trim();
      load(true);
    }, 250);
  });
  els.q.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') { e.preventDefault(); els.q.blur(); } // telefonda klavyeyi kapat
  });

  document.querySelectorAll('.seg-btn').forEach(function (b) {
    b.addEventListener('click', function () {
      document.querySelectorAll('.seg-btn').forEach(function (x) {
        x.classList.toggle('is-active', x === b);
        x.setAttribute('aria-pressed', x === b ? 'true' : 'false');
      });
      state.status = b.getAttribute('data-status');
      load(true);
    });
  });

  els.more.addEventListener('click', function () {
    state.page += 1;
    load(false);
  });

  // Başkaları da işaretliyor: sekme görünürken 10 sn'de bir, sekmeye dönünce hemen arka planda tazele
  window.secimLive.start('hediye', root, poll, POLL_MS);

  setStats(cfg.stats);
  load(true);
})();
