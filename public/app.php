<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

// Onay ekibinin telefondaki ana ekranı: ara, bul, GELDİ.
$user = require_perm('mark');
$event = active_event();
$regionLocked = !can('view_all', $user) && ($user['region'] ?? '') !== '';
$canStance = can('stance', $user);

$regions = ($event && !$regionLocked) ? people_breakdown($user, 'region') : [];
$groups = $event ? people_breakdown($user, 'group_name') : [];
$stances = [];
foreach (stance_options() as $value => [$label, $color]) {
    $stances[] = ['value' => $value, 'label' => $label, 'color' => $color];
}

$config = [
    'api'       => ['search' => url('api/search.php'), 'mark' => url('api/mark.php'), 'stance' => url('api/stance.php')],
    'login'     => url('index.php'),
    'canStance' => $canStance,
    'stances'   => $stances,
    'stats'     => scope_stats($user),
];

/** Filtre çipi satırı (tek seçim; JS yönetir). */
function chip_row(string $filter, string $label, array $items): void
{
    if (count($items) < 2) {
        return; // tek seçenek varsa filtre anlamsız
    }
    echo '<div class="chips" role="group" aria-label="' . e($label) . '" data-filter="' . e($filter) . '">';
    echo '<span class="chips-label">' . e($label) . '</span>';
    foreach ($items as $it) {
        echo '<button type="button" class="chip" data-value="' . e($it['value']) . '" aria-pressed="false">'
            . e($it['label']) . (isset($it['total']) ? ' <span class="chip-count">' . e($it['total']) . '</span>' : '')
            . '</button>';
    }
    echo '</div>';
}

render_header('Onay', 'app', ['onay.css']);
?>
<main class="onay" id="onay" data-config="<?= e(json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>">
<?php if (!$event): ?>
  <div class="container"><div class="card empty">
    <h1>Henüz liste yüklenmedi</h1>
    <p>Yönetici listeyi yükleyince burada görünecek.</p>
  </div></div>
<?php else: ?>
  <div class="onay-head">
    <div class="onay-count" aria-live="polite">
      <div><strong id="cnt-came"><?= e($config['stats']['came']) ?></strong> / <span id="cnt-total"><?= e($config['stats']['total']) ?></span> geldi</div>
      <div class="progress" aria-hidden="true"><span id="cnt-bar"></span></div>
    </div>

    <div class="onay-search">
      <svg class="onay-search-icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
      <input type="search" id="q" class="input" inputmode="search" enterkeyhint="search" autocomplete="off"
             autocorrect="off" spellcheck="false" placeholder="Unvan, yetkili ya da sicil no" aria-label="Ara">
    </div>

    <div class="seg" role="group" aria-label="Durum">
      <button type="button" class="seg-btn is-active" data-status="all" aria-pressed="true">Tümü</button>
      <button type="button" class="seg-btn" data-status="pending" aria-pressed="false">Gelmedi</button>
      <button type="button" class="seg-btn" data-status="came" aria-pressed="false">Geldi</button>
    </div>

    <details class="filters">
      <summary>Filtreler <span id="filter-badge" class="badge" hidden></span></summary>
      <?php
      chip_row('region', 'İlçe', $regions);
      chip_row('group', 'Grup', $groups);
      if ($canStance) {
          chip_row('stance', 'Bizimle mi?', [...$stances, ['value' => NONE_VALUE, 'label' => 'Belirsiz']]);
      }
      ?>
    </details>
  </div>

  <p id="result-info" class="result-info muted" aria-live="polite"></p>
  <div id="list" class="onay-list"></div>
  <div id="empty" class="empty" hidden>Sonuç yok.</div>
  <div class="container"><button type="button" id="more" class="btn btn-block" hidden>Daha fazla göster</button></div>
<?php endif; ?>
  <div id="toast" class="toast" role="status" aria-live="polite"></div>
</main>
<?php render_footer(['onay.js']); ?>
