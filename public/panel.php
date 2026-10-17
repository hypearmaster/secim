<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

// "Panelim": kişinin kendi takımı (kimin adamı) — kaçı geldi, kim gelmedi (ara), oy sonuçları (sadece görür).
$user = require_perm('team');
$event = active_event();

render_header('Panelim', 'panel', ['admin.css', 'panel.css']);

if (!$event) {
    echo '<main class="container"><div class="card"><h1>Henüz liste yüklenmedi</h1>'
        . '<p>Yönetici listeyi yükleyince burada görünecek.</p></div></main>';
    render_footer();
    exit;
}

$d = my_panel_data($user);
$config = [
    'api'     => url('api/panel.php'),
    'eventId' => (int) $event['id'],
    'onay'    => url('app.php'),
    'data'    => $d,
];
?>
<main class="container stack" id="mypanel" data-config="<?= e(json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>">
  <section class="card mp-head">
    <div class="mp-head-row">
      <div><span class="stat-label">Takımım</span><h1>Benim kişilerim</h1></div>
      <a class="btn btn-sm" href="<?= e(url('app.php')) ?>">Onay ekranı</a>
    </div>
    <div class="stats">
      <div class="stat"><div class="stat-label">Takımım</div><div class="stat-value" data-stat="total"><?= e($d['stats']['total']) ?></div></div>
      <div class="stat"><div class="stat-label">Gelen</div><div class="stat-value mp-ok" data-stat="came"><?= e($d['stats']['came']) ?></div></div>
      <div class="stat"><div class="stat-label">Gelmeyen</div><div class="stat-value mp-wait" data-stat="pending"><?= e($d['stats']['pending']) ?></div></div>
      <div class="stat"><div class="stat-label">Oran</div><div class="stat-value"><span data-stat="rate"><?= e($d['stats']['rate']) ?></span>%</div></div>
    </div>
    <div class="progress mp-progress" aria-hidden="true"><span id="mp-bar" style="width: <?= e($d['stats']['rate']) ?>%"></span></div>
    <p class="muted mp-empty" id="mp-empty"<?= $d['stats']['total'] ? ' hidden' : '' ?>>
      Takımınızda henüz kimse yok. Onay ekranında kartlardaki <strong>+ Takımıma al</strong> ile ekleyin.
    </p>
  </section>

  <?php if (can('vote_view', $user)): ?>
  <section class="card vote-summary" id="vote-summary" data-api="<?= e(url('api/vote.php')) ?>" data-interval="3000">
    <div class="vote-summary-head">
      <div><span class="stat-label">Oy sayımı</span><h2>Canlı sonuçlar</h2></div>
      <span class="vote-live" id="vote-live" role="status">Yükleniyor…</span>
    </div>
    <div class="vote-summary-hero">
      <div class="vote-summary-total"><strong id="summary-total">—</strong><span>toplam oy</span></div>
      <p class="vote-leader" id="vote-leader" aria-live="polite">Güncel sonuçlar alınıyor…</p>
    </div>
    <div id="vote-results" class="vote-results" aria-busy="true"><div class="vote-placeholder"></div><div class="vote-placeholder"></div></div>
    <div id="vote-share-bar" class="vote-share-bar" aria-hidden="true"></div>
    <div class="vote-summary-footer"><span class="muted" id="vote-updated">Son kontrol bekleniyor</span><span class="muted">Sadece görüntüleme</span></div>
  </section>
  <?php endif; ?>

  <section class="card">
    <h2>Gelmeyenler <span class="badge badge-warning" id="mp-pending-count"><?= e($d['stats']['pending']) ?></span></h2>
    <ul class="mp-list" id="mp-pending"></ul>
    <p class="muted" id="mp-pending-none" hidden>Takımınızdan gelmeyen kalmadı. 👏</p>
  </section>

  <section class="card">
    <h2>Gelenler <span class="badge badge-success" id="mp-came-count"><?= e($d['stats']['came']) ?></span></h2>
    <ul class="mp-list" id="mp-came"></ul>
    <p class="muted" id="mp-came-none" hidden>Henüz gelen yok.</p>
  </section>

  <section class="card" id="mp-region-card"<?= count($d['region']) > 1 ? '' : ' hidden' ?>>
    <h2>İlçeye göre</h2>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>İlçe</th><th class="num">Takım</th><th class="num">Gelen</th><th class="num">Oran</th></tr></thead>
        <tbody id="mp-region"></tbody>
      </table>
    </div>
  </section>
  <p class="muted" id="mp-truncated"<?= $d['truncated'] ? '' : ' hidden' ?>>Listelerde en fazla 200 firma gösteriliyor; tamamı için onay ekranında “Benimkiler” filtresi.</p>
</main>
<?php render_footer(['live.js', 'panel.js', 'dashboard-votes.js']); ?>
