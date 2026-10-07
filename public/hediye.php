<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require APP_ROOT . '/src/gifts.php';

// Hediye dağıtım ekranı: ara, bul, VERDİK. Bölge kısıtı yok (liste ayrı, bölgesiz).
$user = require_perm('gift_mark');
$event = active_event();
if ($event) sync_gift_regions($event['id']);

$config = [
    'api'   => url('api/gift.php'),
    'login' => url('index.php'),
    'stats' => gift_stats(),
    'regions' => $event ? gift_regions($event['id']) : [],
    'eventId' => $event['id'] ?? null,
];

render_header('Hediye', 'hediye', ['hediye.css']);
?>
<main class="gift" id="gift" data-config="<?= e(json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>">
<?php if (!$event): ?>
  <div class="container"><div class="card empty">
    <h1>Henüz hediye listesi yüklenmedi</h1>
    <p>Yönetici hediye listesini yükleyince burada görünecek.</p>
  </div></div>
<?php else: ?>
  <div class="gift-head">
    <div class="gift-count" aria-live="polite">
      <div><strong id="cnt-given"><?= e($config['stats']['given']) ?></strong> / <span id="cnt-total"><?= e($config['stats']['total']) ?></span> verildi</div>
      <div class="progress" aria-hidden="true"><span id="cnt-bar"></span></div>
    </div>

    <div class="gift-search">
      <svg class="gift-search-icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
      <input type="search" id="q" class="input" inputmode="search" enterkeyhint="search" autocomplete="off"
             autocorrect="off" spellcheck="false" placeholder="İsim ya da not ara" aria-label="Ara">
    </div>

    <div class="gift-head-row">
      <label>Bölge
        <select id="gift-region" class="input">
          <option value="">Tüm bölgeler</option>
          <option value="<?= e(NONE_VALUE) ?>">Bölgesi belirsiz</option>
          <?php foreach ($config['regions'] as $region): ?>
          <option value="<?= e($region) ?>"><?= e($region) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <div class="seg" role="group" aria-label="Durum">
        <button type="button" class="seg-btn is-active" data-status="all" aria-pressed="true">Tümü</button>
        <button type="button" class="seg-btn" data-status="pending" aria-pressed="false">Verilmedi</button>
        <button type="button" class="seg-btn" data-status="given" aria-pressed="false">Verildi</button>
      </div>
      <button type="button" id="gift-add-open" class="btn btn-sm">+ Hediye kişi ekle</button>
    </div>
  </div>

  <p id="result-info" class="result-info muted" aria-live="polite"></p>
  <div id="list" class="gift-list"></div>
  <div id="empty" class="empty" hidden>Sonuç yok.</div>
  <div class="container"><button type="button" id="more" class="btn btn-block" hidden>Daha fazla göster</button></div>

  <dialog id="gift-add" class="gift-dialog">
    <form method="dialog" id="gift-add-form">
      <h2>Hediye kişi ekle</h2>
      <label class="field">
        <span>Ad Soyad</span>
        <input class="input" type="text" id="gift-add-name" maxlength="160" autocomplete="off" required>
      </label>
      <label class="field">
        <span>Not (opsiyonel)</span>
        <input class="input" type="text" id="gift-add-note" maxlength="190" autocomplete="off" placeholder="Firma / açıklama">
      </label>
      <div class="row" style="justify-content:flex-end">
        <button type="button" class="btn btn-ghost" id="gift-add-cancel">Vazgeç</button>
        <button type="submit" class="btn btn-primary" id="gift-add-save">Ekle</button>
      </div>
    </form>
  </dialog>
<?php endif; ?>
  <div id="toast" class="toast" role="status" aria-live="polite"></div>
</main>
<?php render_footer(['hediye.js']); ?>
