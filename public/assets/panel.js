// "Panelim": kişinin kendi takımı. İlk çizim sayfadaki veriden, sonra 10 sn'de bir arka planda tazelenir
// (sekme görünürken; sayfa yenilenmez). Kural: veri DOM'a sadece textContent ile girer (innerHTML yok).
(function () {
  'use strict';

  var root = document.getElementById('mypanel');
  if (!root || !window.secimLive) return;
  var cfg = JSON.parse(root.getAttribute('data-config'));
  var pending = false;
  var last = '';

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined && text !== null) n.textContent = String(text);
    return n;
  }
  function byId(id) { return document.getElementById(id); }

  var SVG_NS = 'http://www.w3.org/2000/svg';
  function callLink(p) {
    if (!p.phone || !/^\+\d{8,15}$/.test(p.phone)) return null;
    var a = el('a', 'mp-call');
    a.href = 'tel:' + p.phone;
    a.setAttribute('aria-label', p.name + ' ara: ' + (p.phone_text || p.phone));
    a.title = p.phone_text || p.phone;
    var svg = document.createElementNS(SVG_NS, 'svg');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('aria-hidden', 'true');
    var path = document.createElementNS(SVG_NS, 'path');
    path.setAttribute('d', 'M6.6 10.8a15.1 15.1 0 0 0 6.6 6.6l2.2-2.2a1 1 0 0 1 1-.25 11.4 11.4 0 0 0 3.6.57 1 1 0 0 1 1 1V20a1 1 0 0 1-1 1A17 17 0 0 1 3 4a1 1 0 0 1 1-1h3.5a1 1 0 0 1 1 1c0 1.25.2 2.45.57 3.57a1 1 0 0 1-.25 1z');
    svg.appendChild(path);
    a.appendChild(svg);
    return a;
  }

  function item(p, came) {
    var li = el('li', 'mp-item' + (came ? ' is-came' : ''));
    var main = el('div', 'mp-main');
    main.appendChild(el('div', 'mp-name', p.name));
    var sub = [p.firm, p.region].filter(Boolean).join(' · ');
    if (sub) main.appendChild(el('div', 'mp-sub', sub));
    li.appendChild(main);
    if (came) {
      li.appendChild(el('div', 'mp-side', '✓ ' + p.time));
    } else {
      if (p.partial) li.appendChild(el('div', 'mp-side', p.partial + ' geldi'));
      var call = callLink(p);
      if (call) li.appendChild(call);
    }
    return li;
  }

  // Rozet = gerçek sayı (stats); liste en fazla 200 olabilir. "Kalmadı" mesajı sadece sayı 0 iken.
  function fillList(id, items, came, count) {
    var ul = byId(id);
    ul.textContent = '';
    items.forEach(function (p) { ul.appendChild(item(p, came)); });
    byId(id + '-none').hidden = count > 0 || !cfg.data.stats.total;
    byId(id + '-count').textContent = count;
  }

  function pick(d) { return { stats: d.stats, region: d.region, pending: d.pending, came: d.came, truncated: d.truncated }; }

  function paint(d) {
    d = pick(d);
    var sig = JSON.stringify(d);
    if (sig === last) return; // değişiklik yok: DOM'a dokunma (kaydırma bozulmasın)
    last = sig;
    cfg.data = d;
    ['total', 'came', 'pending', 'rate'].forEach(function (k) {
      var n = root.querySelector('[data-stat="' + k + '"]');
      if (n) n.textContent = d.stats[k];
    });
    byId('mp-bar').style.width = d.stats.rate + '%';
    byId('mp-empty').hidden = d.stats.total > 0;
    fillList('mp-pending', d.pending, false, d.stats.pending);
    fillList('mp-came', d.came, true, d.stats.came);
    var tb = byId('mp-region');
    tb.textContent = '';
    d.region.forEach(function (r) {
      var tr = el('tr');
      tr.appendChild(el('td', '', r.label));
      tr.appendChild(el('td', 'num', r.total));
      tr.appendChild(el('td', 'num', r.came));
      tr.appendChild(el('td', 'num', r.rate + '%'));
      tb.appendChild(tr);
    });
    byId('mp-region-card').hidden = d.region.length < 2;
    byId('mp-truncated').hidden = !d.truncated;
  }

  function tick() {
    if (pending) return;
    pending = true;
    fetch(cfg.api, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } })
      .then(function (r) {
        if (r.status === 401) { window.location.href = cfg.onay; throw new Error('auth'); }
        return r.json();
      })
      .then(function (res) {
        if (!res.ok) return;
        if (res.event_id !== cfg.eventId) { window.secimNav ? window.secimNav.reload() : window.location.reload(); return; }
        paint(res);
      })
      .catch(function () { /* sessiz: bir sonraki turda tekrar dener */ })
      .finally(function () { pending = false; });
  }

  paint(cfg.data); // ilk çizim sayfadaki veriden
  window.secimLive.start('mypanel', root, tick, 10000);
})();
