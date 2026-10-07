// Onay ekranı: arama, filtre, GELDİ / Geri al, "Bizimle mi?".
// Kural: kişi verisi DOM'a sadece textContent ile girer (innerHTML yok).
(function () {
  'use strict';

  var root = document.getElementById('onay');
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
    came: document.getElementById('cnt-came'),
    total: document.getElementById('cnt-total'),
    bar: document.getElementById('cnt-bar'),
    filterBadge: document.getElementById('filter-badge')
  };

  var state = { q: '', status: 'all', region: '', group: '', stance: '', page: 1 };
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
    els.came.textContent = s.came;
    els.total.textContent = s.total;
    els.bar.style.width = (s.total ? Math.round(s.came / s.total * 100) : 0) + '%';
  }

  function goLogin() { window.location.href = cfg.login; }

  function post(url, data) {
    var body = new URLSearchParams(data);
    var headers = { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' };
    headers[CSRF_HEADER] = csrf;
    return fetch(url, { method: 'POST', body: body, headers: headers, credentials: 'same-origin' })
      .then(function (r) {
        if (r.status === 401) { goLogin(); throw new Error('auth'); }
        return r.json().then(function (j) { j.httpStatus = r.status; return j; });
      });
  }

  /* ---------- kart ---------- */

  function stanceControl(p) {
    var cur = p.stance || { value: null, label: 'Belirsiz', color: 'muted' };
    var wrap = el('label', 'p-stance badge badge-' + cur.color);
    wrap.appendChild(el('span', 'sr-only', 'Bizimle mi?'));
    var sel = el('select', 'p-stance-select');
    var none = el('option', null, 'Bizimle mi? — Belirsiz');
    none.value = '';
    sel.appendChild(none);
    cfg.stances.forEach(function (o) {
      var opt = el('option', null, o.label);
      opt.value = o.value;
      sel.appendChild(opt);
    });
    sel.value = cur.value || '';
    var shown = el('span', null, cur.label);
    wrap.appendChild(shown);
    wrap.appendChild(sel);
    sel.addEventListener('change', function () { changeStance(p.id, sel.value, sel); });
    return wrap;
  }

  function card(p) {
    var c = el('article', 'p-card' + (p.attended ? ' is-came' : ''));
    c.setAttribute('data-id', p.id);

    var main = el('div', 'p-main');
    main.appendChild(el('h3', 'p-name', p.full_name));
    if (p.authority) {
      var a = el('div', 'p-auth');
      a.appendChild(el('span', 'muted', 'Yetkili: '));
      a.appendChild(document.createTextNode(p.authority));
      main.appendChild(a);
    }
    var tags = el('div', 'p-tags');
    if (p.region) tags.appendChild(el('span', 'badge', p.region));
    if (p.group_name) tags.appendChild(el('span', 'badge', p.group_name));
    if (p.ref_no) tags.appendChild(el('span', 'p-ref', 'Sicil ' + p.ref_no));
    if (cfg.canStance) tags.appendChild(stanceControl(p));
    main.appendChild(tags);
    c.appendChild(main);

    var act = el('div', 'p-action');
    if (!p.attended) {
      var b = el('button', 'btn btn-geldi', 'GELDİ');
      b.type = 'button';
      b.setAttribute('aria-label', p.full_name + ' geldi olarak işaretle');
      b.addEventListener('click', function () { mark(p, 'mark', b); });
      act.appendChild(b);
    } else {
      var done = el('div', 'p-done');
      done.appendChild(el('strong', null, '✓ Geldi ' + p.attended_time));
      if (p.attended_by_name) done.appendChild(el('span', 'p-by', p.mine ? 'Siz işaretlediniz' : p.attended_by_name));
      act.appendChild(done);
      if (p.can_unmark) {
        var u = el('button', 'btn btn-sm btn-ghost p-undo', 'Geri al');
        u.type = 'button';
        u.addEventListener('click', function () {
          if (window.confirm(p.full_name + ' için "geldi" işareti geri alınsın mı?')) mark(p, 'unmark', u);
        });
        act.appendChild(u);
      }
    }
    c.appendChild(act);
    return c;
  }

  function replaceCard(p) {
    var old = els.list.querySelector('.p-card[data-id="' + p.id + '"]');
    if (old) old.replaceWith(card(p));
  }

  /* ---------- işlemler ---------- */

  function mark(p, action, btn) {
    btn.disabled = true;
    btn.classList.add('is-loading');
    post(cfg.api.mark, { person_id: p.id, action: action })
      .then(function (res) {
        setStats(res.stats);
        if (res.person) replaceCard(res.person);
        if (res.ok) {
          if (action === 'mark') {
            if (navigator.vibrate) navigator.vibrate(30);
            toast('✓ ' + res.person.full_name + ' geldi', 'success');
          } else {
            toast('İşaret geri alındı');
          }
        } else {
          toast(res.error || 'İşlem yapılamadı', res.httpStatus === 409 ? 'warning' : 'error');
          if (!res.person) { btn.disabled = false; btn.classList.remove('is-loading'); }
        }
      })
      .catch(function (err) {
        if (err.message === 'auth') return;
        btn.disabled = false;
        btn.classList.remove('is-loading');
        toast('Bağlantı yok, tekrar deneyin', 'error');
      });
  }

  function changeStance(id, value, sel) {
    sel.disabled = true;
    post(cfg.api.stance, { person_id: id, value: value })
      .then(function (res) {
        if (res.person) replaceCard(res.person);
        if (res.ok) toast('Kaydedildi: ' + res.person.stance.label, 'success');
        else { toast(res.error || 'Kaydedilemedi', 'error'); sel.disabled = false; }
      })
      .catch(function (err) {
        if (err.message === 'auth') return;
        sel.disabled = false;
        toast('Bağlantı yok, tekrar deneyin', 'error');
      });
  }

  /* ---------- arama / liste ---------- */

  function load(reset) {
    if (reset) state.page = 1;
    if (controller) controller.abort();
    controller = new AbortController();
    var params = new URLSearchParams({
      q: state.q, status: state.status, region: state.region,
      group: state.group, stance: state.stance, page: state.page
    });
    root.classList.add('is-loading');
    els.more.disabled = true;

    fetch(cfg.api.search + '?' + params.toString(), {
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
        res.items.forEach(function (p) { frag.appendChild(card(p)); });
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

  function updateFilterBadge() {
    var n = ['region', 'group', 'stance'].filter(function (k) { return state[k] !== ''; }).length;
    if (!els.filterBadge) return;
    els.filterBadge.hidden = n === 0;
    els.filterBadge.textContent = n;
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

  document.querySelectorAll('.chips').forEach(function (row) {
    var key = row.getAttribute('data-filter');
    row.addEventListener('click', function (e) {
      var chip = e.target.closest('.chip');
      if (!chip) return;
      var val = chip.getAttribute('data-value');
      state[key] = state[key] === val ? '' : val; // tekrar dokununca kalkar
      row.querySelectorAll('.chip').forEach(function (c) {
        var on = c.getAttribute('data-value') === state[key];
        c.classList.toggle('is-active', on);
        c.setAttribute('aria-pressed', on ? 'true' : 'false');
      });
      updateFilterBadge();
      load(true);
    });
  });

  els.more.addEventListener('click', function () {
    state.page += 1;
    load(false);
  });

  // Sekmeye geri dönülünce (başka onaycılar da işaretliyor) listeyi tazele
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible' && Date.now() - lastLoad > 15000) load(true);
  });

  setStats(cfg.stats);
  load(true);
})();
