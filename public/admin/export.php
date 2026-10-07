<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';
require APP_ROOT . '/src/spreadsheet.php';

// Excel'e aktar: etkinlik + filtre seç, ?download=1 ile .xlsx iner.
$user = require_perm('export');

$events = q('SELECT id, name, is_active FROM events ORDER BY is_active DESC, id DESC')->fetchAll();
$active = active_event();
$eventId = (int) ($_GET['event_id'] ?? ($active['id'] ?? ($events[0]['id'] ?? 0)));
$event = null;
foreach ($events as $ev) {
    if ((int) $ev['id'] === $eventId) {
        $event = $ev;
    }
}

$filters = [
    'region' => (string) ($_GET['region'] ?? ''),
    'group'  => (string) ($_GET['group'] ?? ''),
    'status' => in_array($_GET['status'] ?? '', ['came', 'pending'], true) ? $_GET['status'] : 'all',
    'stance' => (string) ($_GET['stance'] ?? ''),
];

if (isset($_GET['download']) && $event) {
    export_xlsx($user, $event, $filters);
}

$regions = $event ? people_breakdown($user, 'region', (int) $event['id']) : [];
$groups = $event ? people_breakdown($user, 'group_name', (int) $event['id']) : [];

/** Basit <select>; seçili değer korunur. */
function filter_select(string $name, string $label, array $options, string $current): void
{
    echo '<label class="field"><span>' . e($label) . '</span><select class="input" name="' . e($name) . '">';
    echo '<option value="">Tümü</option>';
    foreach ($options as $value => $text) {
        echo '<option value="' . e($value) . '"' . ((string) $value === $current ? ' selected' : '') . '>' . e($text) . '</option>';
    }
    echo '</select></label>';
}

render_header('Dışa aktar', 'export', ['admin.css']);
?>
<main class="container">
  <section class="card">
    <h1>Excel'e aktar</h1>
    <?php if (!$event): ?>
      <p class="muted">Henüz liste yok.</p>
    <?php else: ?>
      <p class="muted">Liste + Özet sayfalı .xlsx iner. Telefon, e-posta, adres gibi bilgiler sistemde tutulmadığı için dosyada da yoktur.</p>
      <form method="get" class="stack" id="export-form">
        <label class="field">
          <span>Liste</span>
          <select class="input" name="event_id" onchange="this.form.submit()">
            <?php foreach ($events as $ev): ?>
              <option value="<?= e($ev['id']) ?>"<?= (int) $ev['id'] === (int) $event['id'] ? ' selected' : '' ?>><?= e($ev['name']) ?><?= $ev['is_active'] ? ' (aktif)' : '' ?></option>
            <?php endforeach; ?>
          </select>
          <small>Listeyi değiştirince ilçe/grup seçenekleri yenilenir.</small>
        </label>
        <div class="map-grid">
          <?php
          filter_select('region', 'İlçe', array_column($regions, 'label', 'value'), $filters['region']);
          filter_select('group', 'Grup', array_column($groups, 'label', 'value'), $filters['group']);
          filter_select('status', 'Durum', ['came' => 'Gelenler', 'pending' => 'Gelmeyenler'], $filters['status'] === 'all' ? '' : $filters['status']);
          if (can('stance', $user)) {
              $opts = array_map(fn($o) => $o[0], stance_options()) + [NONE_VALUE => 'Belirsiz'];
              filter_select('stance', 'Bizimle mi?', $opts, $filters['stance']);
          }
          ?>
        </div>
        <button type="submit" name="download" value="1" class="btn btn-primary btn-lg">Excel indir</button>
      </form>
    <?php endif; ?>
  </section>
</main>
<?php render_footer(); ?>
