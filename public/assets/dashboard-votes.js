(function () {
  'use strict';
  var root = document.getElementById('vote-summary');
  if (!root) return;
  var results = document.getElementById('vote-results');
  var live = document.getElementById('vote-live');
  var total = document.getElementById('summary-total');
  var leader = document.getElementById('vote-leader');
  var pending = false, version = -1, eventId;
  if (window.__dashboardVotes) window.__dashboardVotes();
  var timer;
  function element(tag, cls, text) {
    var node = document.createElement(tag);
    node.className = cls;
    if (text != null) node.textContent = text;
    return node;
  }
  function paint(t) {
    total.textContent = t.total;
    var sorted = t.options.slice().sort(function (a, b) { return b.count - a.count; });
    if (!t.total) leader.textContent = 'Henüz oy sayılmadı';
    else if (sorted.length > 1 && sorted[0].count === sorted[1].count) leader.textContent = 'Sayımda beraberlik';
    else leader.textContent = sorted[0].label + ' önde · ' + (sorted[0].count - (sorted[1] ? sorted[1].count : 0)) + ' oy fark';
    results.textContent = '';
    t.options.forEach(function (o) {
      var percent = t.total ? o.count / t.total * 100 : 0;
      var row = element('div', 'vote-result');
      row.style.setProperty('--vote-color', /^#[0-9a-f]{6}$/i.test(o.color) ? o.color : '#64748b');
      var heading = element('div', 'vote-result-head');
      heading.appendChild(element('span', 'vote-result-label', o.label));
      heading.appendChild(element('strong', '', o.count + ' oy · %' + percent.toFixed(1)));
      var bar = element('div', 'vote-result-bar');
      bar.setAttribute('role', 'progressbar');
      bar.setAttribute('aria-label', o.label + ' oy oranı');
      bar.setAttribute('aria-valuemin', '0');
      bar.setAttribute('aria-valuemax', '100');
      bar.setAttribute('aria-valuenow', percent.toFixed(1));
      var fill = element('span', '');
      fill.style.width = percent + '%';
      bar.appendChild(fill);
      row.appendChild(heading); row.appendChild(bar); results.appendChild(row);
    });
    results.setAttribute('aria-busy', 'false');
  }
  function stop() { clearInterval(timer); document.removeEventListener('visibilitychange', visible); }
  function refresh() {
    if (!document.body.contains(root)) { stop(); return; }
    if (pending || document.visibilityState === 'hidden') return;
    pending = true;
    fetch(root.getAttribute('data-api'), { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } })
      .then(function (r) { if (!r.ok) throw new Error('fetch'); return r.json(); })
      .then(function (res) {
        if (!document.body.contains(root)) return;
        if (!res.ok || !res.tallies) throw new Error('data');
        if (eventId !== undefined && eventId !== res.event_id) { stop(); window.secimNav ? window.secimNav.reload() : window.location.reload(); return; }
        eventId = res.event_id;
        if (typeof res.tallies.ver !== 'number' || res.tallies.ver > version) { paint(res.tallies); version = res.tallies.ver; }
        live.textContent = '● Canlı'; live.classList.remove('is-offline');
      })
      .catch(function () { if (document.body.contains(root)) { live.textContent = 'Bağlantı bekleniyor'; live.classList.add('is-offline'); } })
      .finally(function () { pending = false; });
  }
  function visible() { if (document.visibilityState === 'visible') refresh(); }
  window.__dashboardVotes = stop;
  document.addEventListener('visibilitychange', visible);
  timer = setInterval(refresh, 1000);
  refresh();
})();
