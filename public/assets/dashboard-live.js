// Panel: sayılar ve tablolar 10 sn'de bir arka planda tazelenir (sekme görünürken); sayfa yenilenmez.
// Sadece içeriği değişen tablo gövdesi / sayı güncellenir: kaydırma ve açık öğeler bozulmaz.
// Aktif liste değişirse ya da boş bir tablo dolarsa (tablo yok) tam yenileme. Veri DOM'a sadece textContent ile girer.
(function () {
  'use strict';
  var root = document.getElementById('dash');
  if (!root || !window.secimLive) return;

  var api = root.getAttribute('data-api');
  var eventId = parseInt(root.getAttribute('data-event'), 10);
  var pending = false;

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined && text !== null) n.textContent = String(text);
    return n;
  }
  function td(text, cls) { return el('td', cls || '', text); }

  function barRow(r) {
    var tr = el('tr');
    tr.appendChild(td(r.label));
    tr.appendChild(td(r.total, 'num'));
    tr.appendChild(td(r.came, 'num'));
    tr.appendChild(td(r.rate + '%', 'num'));
    var bar = el('td', 'col-bar');
    var prog = el('div', 'progress');
    prog.style.width = '100%';
    prog.style.maxWidth = '200px';
    var fill = el('span');
    fill.style.width = r.rate + '%';
    prog.appendChild(fill);
    bar.appendChild(prog);
    tr.appendChild(bar);
    return tr;
  }

  function stanceRow(r) {
    var tr = el('tr');
    var c = el('td');
    c.appendChild(el('span', 'badge badge-' + r.color, r.label));
    tr.appendChild(c);
    tr.appendChild(td(r.total, 'num'));
    tr.appendChild(td(r.came, 'num'));
    tr.appendChild(td(r.rate + '%', 'num'));
    return tr;
  }

  function logRow(l) {
    var tr = el('tr', l.row || '');
    tr.appendChild(td(l.time));
    var a = el('td');
    a.appendChild(el('span', 'badge badge-' + l.badge, l.label));
    tr.appendChild(a);
    tr.appendChild(td(l.name));
    tr.appendChild(td(l.region));
    tr.appendChild(td(l.user));
    return tr;
  }

  var builders = { region: barRow, group: barRow, stance: stanceRow, logs: logRow };

  function fullReload() { window.secimNav ? window.secimNav.reload() : window.location.reload(); }

  function apply(res) {
    var s = res.stats;
    ['total', 'came', 'pending', 'rate'].forEach(function (k) {
      var n = root.querySelector('[data-stat="' + k + '"]');
      if (n && n.textContent !== String(s[k])) n.textContent = s[k];
    });
    var rebuild = false;
    Object.keys(builders).forEach(function (key) {
      var rows = key === 'logs' ? res.logs : res[key];
      var body = root.querySelector('tbody[data-dash="' + key + '"]');
      if (!body) {
        if (rows && rows.length) rebuild = true; // boş kartta ilk veri geldi: tablo iskeleti yok
        return;
      }
      var sig = JSON.stringify(rows);
      if (body.__sig === sig) return;
      body.__sig = sig;
      var frag = document.createDocumentFragment();
      rows.forEach(function (r) { frag.appendChild(builders[key](r)); });
      body.textContent = '';
      body.appendChild(frag);
    });
    if (rebuild) fullReload();
  }

  function poll() {
    if (pending) return;
    pending = true;
    fetch(api, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } })
      .then(function (r) {
        if (r.status === 401) { window.location.href = (document.body.getAttribute('data-base') || '') + '/index.php'; throw new Error('auth'); }
        return r.json();
      })
      .then(function (res) {
        if (!document.body.contains(root) || !res.ok) return;
        if (res.event_id !== eventId) { fullReload(); return; } // aktif liste değişti
        apply(res);
      })
      .catch(function () { /* sessiz: bir sonraki turda tekrar dener */ })
      .finally(function () { pending = false; });
  }

  window.secimLive.start('dashboard', root, poll, 10000);
})();
