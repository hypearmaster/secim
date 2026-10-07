<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

// Onay ekibinin telefondaki ana ekranı: ara, bul, GELDİ.
$user = require_perm('mark');
$event = active_event();
$regionLocked = !can('view_all', $user) && ($user['region'] ?? '') !== '';
$canStance = can('stance', $user);
$canAdd = can('add_person', $user);

$regions = ($event && !$regionLocked) ? people_breakdown($user, 'region') : [];
$groups = $event ? people_breakdown($user, 'group_name') : [];
$stances = [];
foreach (stance_options() as $value => [$label, $color]) {
    $stances[] = ['value' => $value, 'label' => $label, 'color' => $color];
}

$config = [
    'api'       => ['search' => url('api/search.php'), 'mark' => url('api/mark.php'), 'stance' => url('api/stance.php'),
                    'person' => url('api/person.php')],
    'login'     => url('index.php'),
    'canStance' => $canStance,
    'supporterStance' => $canStance ? (string) config('supporter_stance', 'bizimle') : null, // mavi zemin
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
             autocorrect="off" spellcheck="false" placeholder="Ad soyad, firma ya da sicil no" aria-label="Ara">
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

  <div class="result-bar">
    <p id="result-info" class="result-info muted" aria-live="polite"></p>
    <?php if ($canAdd): ?>
      <button type="button" class="btn btn-sm btn-primary" id="add-open">+ Yeni kişi</button>
    <?php endif; ?>
  </div>
  <div id="list" class="onay-list"></div>
  <div id="empty" class="empty" hidden>Sonuç yok.</div>
  <div class="container"><button type="button" id="more" class="btn btn-block" hidden>Daha fazla göster</button></div>
  <?php if ($canAdd):
      $groupNames = array_values(array_filter(array_column($groups, 'value'), fn($v) => $v !== NONE_VALUE));
      $regionNames = array_values(array_filter(array_column(people_breakdown($user, 'region'), 'value'), fn($v) => $v !== NONE_VALUE)); ?>
  <dialog class="modal" id="add-modal" aria-labelledby="add-title">
    <form class="stack modal-body" id="add-form" data-no-nav novalidate>
      <div class="modal-head">
        <h2 id="add-title">Yeni kişi ekle</h2>
        <button type="button" class="btn btn-ghost btn-sm" data-close aria-label="Kapat">✕</button>
      </div>
      <div class="alert alert-error" id="add-error" role="alert" hidden></div>
      <label class="field">
        <span>Ad soyad *</span>
        <input class="input" type="text" name="name" required maxlength="160" autocomplete="off">
      </label>
      <label class="field">
        <span>Firma / şirket</span>
        <input class="input" type="text" name="firm" maxlength="160" autocomplete="off">
        <small>Varsa yazın; kartta adın altında görünür.</small>
      </label>
      <div class="modal-grid">
        <label class="field">
          <span>Telefon</span>
          <input class="input" type="tel" name="phone" inputmode="tel" maxlength="40" autocomplete="off" placeholder="0532 123 45 67">
        </label>
        <label class="field">
          <span>Sicil no</span>
          <input class="input" type="text" name="ref_no" maxlength="64" autocomplete="off" inputmode="numeric" placeholder="<?= e(config('placeholder_ref', '11111111')) ?>">
          <small>Boş bırakılırsa <?= e(config('placeholder_ref', '11111111')) ?> yazılır.</small>
        </label>
        <label class="field">
          <span>İlçe</span>
          <input class="input" type="text" name="region" maxlength="120" list="add-regions" autocomplete="off">
          <datalist id="add-regions"><?php foreach ($regionNames as $r): ?><option value="<?= e($r) ?>"><?php endforeach; ?></datalist>
        </label>
        <label class="field">
          <span>Grup</span>
          <input class="input" type="text" name="group" maxlength="120" list="add-groups" autocomplete="off" value="<?= e(count($groupNames) === 1 ? $groupNames[0] : '') ?>">
          <datalist id="add-groups"><?php foreach ($groupNames as $g): ?><option value="<?= e($g) ?>"><?php endforeach; ?></datalist>
        </label>
      </div>
      <div class="row modal-actions">
        <button type="button" class="btn btn-ghost" data-close>Vazgeç</button>
        <button type="submit" class="btn btn-primary" id="add-submit">Ekle</button>
      </div>
    </form>
  </dialog>
  <?php endif; ?>
<?php endif; ?>
  <div id="toast" class="toast" role="status" aria-live="polite"></div>
</main>
<?php render_footer(['onay.js']); ?>
