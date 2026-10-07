const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
function node() {
  return { textContent: '', children: [], style: { setProperty() {} }, classList: { remove() {}, add() {} },
    setAttribute() {}, getAttribute() { return '/api/vote'; }, appendChild(n) { this.children.push(n); } };
}
const els = Object.fromEntries(['vote-summary', 'vote-results', 'vote-live', 'summary-total', 'vote-leader', 'vote-share-bar', 'vote-updated'].map(id => [id, node()]));
let attached = true, refresh, stopped = false, response;
const context = { document: { visibilityState: 'visible', body: { contains: () => attached },
  getElementById: id => els[id], createElement: node, addEventListener() {}, removeEventListener() {} },
  window: {}, setInterval(fn) { refresh = fn; return 1; }, clearInterval() { stopped = true; },
  fetch: async () => ({ ok: true, json: async () => response }) };
const tally = (ver, blue, red) => ({ ok: true, event_id: 1, tallies: { ver, total: blue + red,
  options: [{ label: 'Mavi', color: '#1d4ed8', count: blue }, { label: 'Kırmızı', color: '#dc2626', count: red }] } });
const settle = () => new Promise(resolve => setImmediate(resolve));
(async () => {
  response = tally(0, 0, 0);
  vm.runInNewContext(fs.readFileSync(__dirname + '/../public/assets/dashboard-votes.js', 'utf8'), context);
  await settle();
  assert.equal(els['vote-leader'].textContent, 'Henüz oy sayılmadı');
  response = tally(1, 3, 3); refresh(); await settle();
  assert.equal(els['vote-leader'].textContent, 'Sayımda beraberlik');
  response = tally(2, 8, 2); refresh(); await settle();
  assert.equal(els['summary-total'].textContent, 10);
  assert.equal(els['vote-leader'].textContent, 'Mavi önde · 6 oy fark');
  const rows = els['vote-results'].children;
  assert.equal(rows[rows.length - 2].children[1].textContent, 8);
  assert.equal(rows[rows.length - 2].children[2].textContent, '%80.0');
  assert.equal(rows[rows.length - 2].children[0].children[1].textContent, 'Önde');
  response = tally(1, 1, 1); refresh(); await settle();
  assert.equal(els['summary-total'].textContent, 10);
  context.fetch = async () => { throw new Error('offline'); }; refresh(); await settle();
  assert.equal(els['vote-live'].textContent, 'Bağlantı bekleniyor');
  assert.equal(els['summary-total'].textContent, 10);
  attached = false; refresh(); assert.equal(stopped, true);
  console.log('OK: boş sayım, beraberlik, lider/fark/yüzde, eski yanıt, bağlantı ve sayfadan ayrılma');
})().catch(error => { console.error(error); process.exitCode = 1; });
