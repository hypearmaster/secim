<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';
require APP_ROOT . '/src/votes.php';

// Oy sayacı: büyük renkli butonlar, her tıkta +1, anlık toplam. Sadece yöneticiler.
$user = require_perm('vote');
$event = active_event();
$canReset = can('vote_reset', $user);

$config = [
    'api'      => url('api/vote.php'),
    'login'    => url('index.php'),
    'canReset' => $canReset,
    'tallies'  => $event ? vote_tallies($event['id']) : ['options' => [], 'total' => 0, 'last' => null],
];

render_header('Oy Sayacı', 'counter', ['counter.css']);
?>
<main class="counter" id="counter" data-config="<?= e(json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>">
<?php if (!$event): ?>
  <div class="container"><div class="card empty">
    <h1>Aktif etkinlik yok</h1>
    <p>Sayacı kullanmak için önce bir liste/etkinlik aktif olmalı.</p>
  </div></div>
<?php else: ?>
  <div class="counter-head">
    <div class="counter-total">
      <span class="counter-total-label">Toplam</span>
      <strong id="vote-total"><?= e($config['tallies']['total']) ?></strong>
    </div>
    <div class="counter-last" id="vote-last" aria-live="polite"></div>
  </div>

  <div class="counter-grid" id="vote-grid">
    <!-- butonlar JS ile çizilir (renkler config'den) -->
  </div>

  <div class="counter-actions">
    <button type="button" class="btn btn-lg btn-ghost" id="vote-undo">↶ Son sayımı geri al</button>
    <?php if ($canReset): ?>
      <button type="button" class="btn btn-lg btn-ghost counter-reset" id="vote-reset">Sıfırla</button>
    <?php endif; ?>
  </div>
  <p class="counter-note muted">Her dokunuş o renge +1 yazar; birden çok kişi aynı anda sayabilir. Yanlış dokunuşu “Son sayımı geri al” ile düzeltin.</p>
<?php endif; ?>
  <div id="toast" class="toast" role="status" aria-live="polite"></div>
</main>
<?php render_footer(['counter.js']); ?>
