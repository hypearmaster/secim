<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';
require APP_ROOT . '/src/spreadsheet.php';
require APP_ROOT . '/src/supporters.php';
require APP_ROOT . '/src/gifts.php';

// Hediye listesi yönetimi (admin): sadece "ad" sütunlu .xlsx/.csv yükle (yenisi eskisinin yerine geçer), sil.
$user = require_perm('gift_manage');
$event = active_event();

if (is_post()) {
    csrf_verify();
    if (!$event) {
        redirect('admin/gifts.php');
    }
    // Form açıkken aktif liste değiştiyse işlem başka listeyi silmesin
    if ((int) ($_POST['event_id'] ?? 0) !== (int) $event['id']) {
        flash('error', 'Aktif liste değişti, işlem yapılmadı. Sayfayı yenileyip tekrar deneyin.');
        redirect('admin/gifts.php');
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'upload') {
        $f = $_FILES['file'] ?? null;
        $ext = upload_ext($f);
        if ($problem = upload_problem($f)) {
            flash('error', $problem);
        } else {
            try {
                // Geçici dosya PHP'nin kendi tmp'sinde; tek adımda okunur, saklanmaz.
                $names = read_name_list($f['tmp_name'], $ext);
                if (!$names) {
                    throw new RuntimeException('Dosyada isim bulunamadı.');
                }
                $added = import_gifts($event['id'], $names, $user['id']);
                flash('success', "$added kişi hediye listesine yüklendi; önceki liste değiştirildi.");
                redirect('hediye.php');
            } catch (RuntimeException $e) {
                flash('error', $e->getMessage());
            }
        }
        redirect('admin/gifts.php');
    }

    if ($action === 'clear') {
        clear_gifts($event['id']);
        audit('gifts_clear', $event['name']);
        flash('success', 'Hediye listesi silindi.');
        redirect('admin/gifts.php');
    }
    redirect('admin/gifts.php');
}

$stats = $event ? gift_stats() : null;
// Önizleme: yüklü listenin ilk 20 ismi
$preview = $event ? search_gifts([], 20)['items'] : [];

render_header('Hediye listesi', 'gifts', ['admin.css']);
?>
<main class="container stack">
<?php if (!$event): ?>
  <div class="card empty"><h1>Aktif liste yok</h1><p>Önce üye listesini (etkinliği) yükleyin.</p></div>
<?php else: ?>
  <div class="stats">
    <div class="stat"><div class="stat-label">Hediye kişi</div><div class="stat-value"><?= e($stats['total']) ?></div></div>
    <div class="stat"><div class="stat-label">Verildi</div><div class="stat-value" style="color:var(--success)"><?= e($stats['given']) ?></div></div>
    <div class="stat"><div class="stat-label">Kalan</div><div class="stat-value" style="color:var(--warning)"><?= e($stats['total'] - $stats['given']) ?></div></div>
  </div>

  <section class="card">
    <h2>Hediye listesini yükle</h2>
    <p class="muted">Sadece <strong>ad</strong> sütunu olan .xlsx ya da .csv. Aktif etkinliğe (“<?= e($event['name']) ?>”) yüklenir; varsa önceki hediye listesi <strong>yerine geçer</strong> (verildi işaretleri dahil sıfırlanır). Sahada onay ekibi ayrıca tek tek kişi ekleyebilir.</p>
    <form method="post" enctype="multipart/form-data" class="row">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="upload">
      <input type="hidden" name="event_id" value="<?= e($event['id']) ?>">
      <input class="input" type="file" name="file" accept=".xlsx,.csv" required style="flex:1;min-width:220px">
      <button class="btn btn-primary" type="submit"<?= $stats['total'] ? ' onclick="return confirm(\'Mevcut hediye listesi silinip yenisi yüklenecek. Devam?\')"' : '' ?>>Yükle</button>
    </form>
    <?php if ($stats['total']): ?>
      <form method="post" style="margin-top:12px" onsubmit="return confirm('Hediye listesi tamamen silinecek. Emin misiniz?')">
        <?= csrf_field() ?><input type="hidden" name="event_id" value="<?= e($event['id']) ?>"><input type="hidden" name="action" value="clear">
        <button class="btn btn-sm btn-danger" type="submit">Listeyi sil</button>
      </form>
    <?php endif; ?>
  </section>

  <?php if ($preview): ?>
  <section class="card">
    <h2>Liste (ilk 20)</h2>
    <div class="sup-list">
      <?php foreach ($preview as $g): ?>
        <div class="sup-row<?= $g['given'] ? ' is-came' : '' ?>">
          <div class="sup-main">
            <strong><?= e($g['name']) ?></strong>
            <?php if ($g['note']): ?><span class="muted"> · <?= e($g['note']) ?></span><?php endif; ?>
          </div>
          <?php if ($g['given']): ?><span class="badge badge-success">✓ Verildi<?= $g['given_time'] ? ' ' . e($g['given_time']) : '' ?></span><?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <p class="muted" style="margin-top:10px"><a class="btn btn-sm" href="<?= e(url('hediye.php')) ?>">Hediye ekranını aç</a></p>
  </section>
  <?php endif; ?>
<?php endif; ?>
</main>
<?php render_footer(); ?>
