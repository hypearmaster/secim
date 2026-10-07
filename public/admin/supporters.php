<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';
require APP_ROOT . '/src/spreadsheet.php';
require APP_ROOT . '/src/supporters.php';

// Bizimkiler: isim listesi + üye listesiyle eşleşme + kim geldi.
// Görmek: admin, yönetici. Yüklemek / eşleştirmek / indirmek: sadece admin.
$user = require_perm('supporters');
$canManage = can('manage_supporters', $user);
$event = active_event();

if (is_post()) {
    $user = require_perm('manage_supporters');
    csrf_verify();
    if (!$event) {
        redirect('admin/supporters.php');
    }
    $action = $_POST['action'] ?? '';
    $back = 'admin/supporters.php?' . http_build_query(array_filter(['filter' => $_POST['filter'] ?? '', 'find' => $_POST['find'] ?? '']));

    if ($action === 'upload') {
        $f = $_FILES['file'] ?? null;
        $ext = strtolower(pathinfo((string) ($f['name'] ?? ''), PATHINFO_EXTENSION));
        if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || ($f['size'] ?? 0) > IMPORT_MAX_BYTES) {
            flash('error', 'Dosya yüklenemedi (en fazla 5 MB).');
        } elseif (!in_array($ext, ['xlsx', 'csv'], true)) {
            flash('error', 'Sadece .xlsx ya da .csv yükleyin.');
        } else {
            try {
                // Geçici dosya PHP'nin kendi tmp'sinde kalır; tek adımda okunur, saklanmaz
                $names = read_name_list($f['tmp_name'], $ext);
                if (!$names) {
                    throw new RuntimeException('Dosyada isim bulunamadı.');
                }
                $s = import_supporters($event['id'], $names, $user['id']);
                audit('supporters_import', basename((string) $f['name']), ['liste' => $event['name'], 'isim' => count($names)] + $s);
                flash('success', count($names) . " isim yüklendi: {$s['matched']} eşleşti, {$s['suggested']} onay bekliyor, {$s['none']} bulunamadı.");
            } catch (RuntimeException $e) {
                flash('error', $e->getMessage());
            }
        }
        redirect('admin/supporters.php');
    }

    if ($action === 'clear') {
        clear_supporters($event['id']);
        audit('supporters_clear', $event['name']);
        flash('success', 'Bizimkiler listesi silindi; verdiği "Bizimle" işaretleri kaldırıldı.');
        redirect('admin/supporters.php');
    }

    $sup = find_supporter($event['id'], (int) ($_POST['id'] ?? 0));
    if (!$sup) {
        flash('error', 'Kayıt bulunamadı.');
        redirect($back);
    }
    $pid = (int) ($_POST['person_id'] ?? 0);
    switch ($action) {
        case 'confirm':
            confirm_supporter($sup);
            audit('supporter_link', $sup['name'], ['onay' => 'tahmin doğru']);
            flash('success', "{$sup['name']}: eşleşme onaylandı.");
            break;
        case 'reject':
            reject_supporter($sup);
            audit('supporter_unlink', $sup['name'], ['sebep' => 'tahmin yanlış']);
            flash('info', "{$sup['name']}: tahmin kaldırıldı, elle bağlayabilirsiniz.");
            break;
        case 'link':
            if (link_supporter($sup, $pid)) {
                audit('supporter_link', $sup['name'], ['kişi' => $pid]);
                flash('success', "{$sup['name']} bağlandı.");
                $back = 'admin/supporters.php?filter=review';
            } else {
                flash('error', 'Kişi bu listede değil.');
            }
            break;
        case 'unlink':
            unlink_supporter($sup, $pid);
            audit('supporter_unlink', $sup['name'], ['kişi' => $pid]);
            flash('info', "{$sup['name']}: bağ kaldırıldı.");
            break;
    }
    redirect($back);
}

// Excel indir (admin)
if (isset($_GET['download']) && $event) {
    require_perm('manage_supporters');
    $rows = list_supporters($event['id']);
    $book = new PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sh = $book->getActiveSheet();
    $sh->setTitle('Bizimkiler');
    $titles = ['İsim (bizim liste)', 'Eşleşme', 'Ad Soyad (Yetkili)', 'Firma (Unvan)', 'Sicil No', 'İlçe', 'Durum', 'Geliş saati'];
    xlsx_header($sh, $titles, 0);
    $r = 2;
    $label = ['matched' => 'Eşleşti', 'suggested' => 'Onay bekliyor', 'none' => 'Bulunamadı'];
    foreach ($rows as $s) {
        foreach ($s['people'] ?: [null] as $p) {
            $vals = [$s['name'], $label[$s['status']], $p['authority'] ?? ($p['full_name'] ?? ''), ($p['authority'] ?? null) ? $p['full_name'] : '',
                     $p['ref_no'] ?? '', $p['region'] ?? '', $p ? ($p['attended_at'] ? 'Geldi' : 'Gelmedi') : '', fmt_datetime($p['attended_at'] ?? null)];
            foreach ($vals as $i => $v) {
                xlsx_text($sh, PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1) . $r, $v);
            }
            $r++;
        }
    }
    $sh->setAutoFilter('A1:H' . max(1, $r - 1));
    foreach (range(1, 8) as $i) {
        $sh->getColumnDimensionByColumn($i)->setAutoSize(true);
    }
    audit('export', 'Bizimkiler — ' . $event['name'], ['satır' => $r - 2]);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="bizimkiler-' . (new DateTimeImmutable('now', local_tz()))->format('Ymd-Hi') . '.xlsx"');
    header('Cache-Control: no-store');
    (new PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save('php://output');
    exit;
}

$filter = in_array($_GET['filter'] ?? '', ['came', 'pending', 'review'], true) ? $_GET['filter'] : '';
$findId = (int) ($_GET['find'] ?? 0);
$stats = $event ? supporter_stats($event['id']) : null;
$rows = $event ? list_supporters($event['id'], $filter) : [];
$find = ($canManage && $event && $findId) ? find_supporter($event['id'], $findId) : null;
$findQ = $find ? (string) ($_GET['q'] ?? $find['name']) : '';
$findResults = $find ? search_people($user, ['q' => $findQ], 10)['items'] : [];

/** Kayıt satırında kullanılan küçük POST formu. */
function sup_button(int $id, string $action, string $label, string $cls, string $filter, int $personId = 0, string $confirm = ''): string
{
    return '<form method="post" class="inline"' . ($confirm ? ' onsubmit="return confirm(\'' . e($confirm) . '\')"' : '') . '>'
        . csrf_field()
        . '<input type="hidden" name="action" value="' . e($action) . '">'
        . '<input type="hidden" name="id" value="' . $id . '">'
        . ($personId ? '<input type="hidden" name="person_id" value="' . $personId . '">' : '')
        . '<input type="hidden" name="filter" value="' . e($filter) . '">'
        . '<button type="submit" class="btn btn-sm ' . e($cls) . '">' . e($label) . '</button></form>';
}

render_header('Bizimkiler', 'supporters', ['admin.css']);
?>
<main class="container stack">
<?php if (!$event): ?>
  <div class="card empty"><h1>Aktif liste yok</h1><p>Önce üye listesini yükleyin.</p></div>
<?php else: ?>
  <div class="stats">
    <div class="stat"><div class="stat-label">Bizim liste</div><div class="stat-value"><?= e($stats['total']) ?></div></div>
    <div class="stat"><div class="stat-label">Eşleşen</div><div class="stat-value"><?= e($stats['matched']) ?></div></div>
    <div class="stat"><div class="stat-label">Gelen</div><div class="stat-value" style="color:var(--success)"><?= e($stats['came']) ?></div></div>
    <div class="stat"><div class="stat-label">Kontrol bekleyen</div><div class="stat-value" style="color:var(--warning)"><?= e($stats['suggested'] + $stats['none']) ?></div></div>
  </div>

  <?php if ($find): ?>
  <section class="card">
    <h2>“<?= e($find['name']) ?>” için firma bul</h2>
    <form method="get" class="row">
      <input type="hidden" name="find" value="<?= e($find['id']) ?>">
      <input class="input" type="search" name="q" value="<?= e($findQ) ?>" style="flex:1;min-width:200px" placeholder="Ad, soyad ya da firma">
      <button class="btn" type="submit">Ara</button>
      <a class="btn btn-ghost" href="<?= e(url('admin/supporters.php?filter=review')) ?>">Kapat</a>
    </form>
    <div class="sup-list" style="margin-top:12px">
      <?php foreach ($findResults as $p): ?>
        <div class="sup-row">
          <div class="sup-main">
            <strong><?= e($p['authority'] ?: $p['full_name']) ?></strong>
            <?php if ($p['authority']): ?><div class="muted"><?= e($p['full_name']) ?></div><?php endif; ?>
            <div class="sup-tags"><span class="badge"><?= e($p['region'] ?? '—') ?></span><?php if ($p['ref_no']): ?><span class="muted">Sicil <?= e($p['ref_no']) ?></span><?php endif; ?></div>
          </div>
          <?= sup_button($find['id'], 'link', 'Bağla', 'btn-success', 'review', $p['id']) ?>
        </div>
      <?php endforeach; ?>
      <?php if (!$findResults): ?><p class="muted">Sonuç yok. Sadece soyadı ya da firma adının bir kısmını deneyin. Bu kişi başka bir grubun listesinde olabilir.</p><?php endif; ?>
    </div>
  </section>
  <?php endif; ?>

  <section class="card">
    <div class="row" style="justify-content:space-between">
      <h2 style="margin:0">Liste</h2>
      <?php if ($canManage && $stats['total']): ?>
        <a class="btn btn-sm" href="<?= e(url('admin/supporters.php?download=1')) ?>">Excel indir</a>
      <?php endif; ?>
    </div>
    <nav class="row sup-filters" aria-label="Filtre">
      <?php foreach (['' => 'Tümü', 'came' => 'Gelenler', 'pending' => 'Gelmeyenler', 'review' => 'Kontrol bekleyen'] as $k => $lbl): ?>
        <a class="chip<?= $filter === $k ? ' is-active' : '' ?>" href="<?= e(url('admin/supporters.php' . ($k ? "?filter=$k" : ''))) ?>"><?= e($lbl) ?></a>
      <?php endforeach; ?>
    </nav>

    <div class="sup-list">
    <?php foreach ($rows as $s): ?>
      <div class="sup-row sup-<?= e($s['status']) ?><?= $s['came'] ? ' is-came' : '' ?>">
        <div class="sup-main">
          <div class="row" style="gap:6px">
            <strong><?= e($s['name']) ?></strong>
            <?php if ($s['status'] === 'suggested'): ?><span class="badge badge-warning">Tahmin — onay bekliyor</span>
            <?php elseif ($s['status'] === 'none'): ?><span class="badge badge-danger">Bulunamadı</span>
            <?php elseif ($s['came']): ?><span class="badge badge-success">✓ Geldi</span>
            <?php else: ?><span class="badge">Gelmedi</span><?php endif; ?>
          </div>
          <?php foreach ($s['people'] as $p): ?>
            <div class="sup-person">
              <span><?= e($p['authority'] ?: $p['full_name']) ?><?php if ($p['authority']): ?> <span class="muted">· <?= e($p['full_name']) ?></span><?php endif; ?></span>
              <span class="muted"><?= e($p['region'] ?? '') ?><?= $p['attended_at'] ? ' · geldi ' . e(fmt_time($p['attended_at'])) : '' ?></span>
              <?php if ($canManage): ?><?= sup_button($s['id'], 'unlink', '✕', 'btn-ghost sup-x', $filter, (int) $p['id'], 'Bu firma bağı kaldırılsın mı?') ?><?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
        <?php if ($canManage): ?>
          <div class="sup-actions">
            <?php if ($s['status'] === 'suggested'): ?>
              <?= sup_button($s['id'], 'confirm', 'Doğru', 'btn-success', $filter) ?>
              <?= sup_button($s['id'], 'reject', 'Hiçbiri', 'btn-ghost', $filter) ?>
            <?php endif; ?>
            <?php if ($s['status'] !== 'matched' || $s['people']): ?>
              <a class="btn btn-sm btn-ghost" href="<?= e(url('admin/supporters.php?find=' . $s['id'])) ?>"><?= $s['people'] ? '+ Firma' : 'Firma bul' ?></a>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
    <?php if (!$rows): ?><p class="empty"><?= $stats['total'] ? 'Bu filtrede kayıt yok.' : 'Henüz bizim liste yüklenmedi.' ?></p><?php endif; ?>
    </div>
  </section>

  <?php if ($canManage): ?>
  <section class="card">
    <h2>Bizim listeyi yükle</h2>
    <p class="muted">Ad soyad sütunu olan .xlsx ya da .csv. Aktif listeye (“<?= e($event['name']) ?>”) göre eşleştirilir; varsa önceki bizim liste <strong>yerine geçer</strong>.
      Tam eşleşenler otomatik “Bizimle” olur, benzerler onayınızı bekler.</p>
    <form method="post" enctype="multipart/form-data" class="row">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="upload">
      <input class="input" type="file" name="file" accept=".xlsx,.csv" required style="flex:1;min-width:220px">
      <button class="btn btn-primary" type="submit"<?= $stats['total'] ? ' onclick="return confirm(\'Mevcut bizim liste silinip yenisi yüklenecek. Devam?\')"' : '' ?>>Yükle</button>
    </form>
    <?php if ($stats['total']): ?>
      <form method="post" style="margin-top:12px" onsubmit="return confirm('Bizim liste silinecek ve verdiği Bizimle işaretleri kaldırılacak. Emin misiniz?')">
        <?= csrf_field() ?><input type="hidden" name="action" value="clear">
        <button class="btn btn-sm btn-danger" type="submit">Bizim listeyi sil</button>
      </form>
    <?php endif; ?>
  </section>
  <?php endif; ?>
<?php endif; ?>
</main>
<?php render_footer(); ?>
