// Hediye ekranı: arama, filtre, VERDİK / Geri al, elle kişi ekle.
// Kural: kişi verisi DOM'a sadece textContent ile girer (innerHTML yok).
(function () {
  'use strict';

  var root = document.getElementById('gift');
  if (!root || !document.getElementById('list')) return;

  var cfg = JSON.parse(root.getAttribute('data-config'));
  var CSRF_HEADER = 'X-CSRF-Token';
  var csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

  var els = {
    q: document.getElementById('q'),
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

  var state = { q: '', status: 'all', page: 1 };
  var controller = null;
  var lastLoad = 0;
  var debounce = null;

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

  function post(data) {
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
    return c;
  }

  function replaceCard(g) {
    var old = els.list.querySelector('.g-card[data-id="' + g.id + '"]');
    if (old) old.replaceWith(card(g));
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
    if (reset) state.page = 1;
    if (controller) controller.abort();
    controller = new AbortController();
    var params = new URLSearchParams({ q: state.q, status: state.status, page: state.page });
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
        lastLoad = Date.now();
        if (!res.ok) { toast(res.error || 'Liste alınamadı', 'error'); return; }
        if (reset) els.list.textContent = '';
        var frag = document.createDocumentFragment();
        res.items.forEach(function (g) { frag.appendChild(card(g)); });
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
        root.classList.remove('is-loading');
        els.more.disabled = false;
      });
  }

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

  // Sekmeye geri dönülünce (başkaları da işaretliyor) listeyi tazele
  if (window.__giftVisibility) document.removeEventListener('visibilitychange', window.__giftVisibility);
  window.__giftVisibility = function () {
    if (!document.body.contains(root)) return; // hediye ekranından ayrıldık
    if (document.visibilityState === 'visible' && Date.now() - lastLoad > 15000) load(true);
  };
  document.addEventListener('visibilitychange', window.__giftVisibility);

  setStats(cfg.stats);
  load(true);
})();
