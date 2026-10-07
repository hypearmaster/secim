<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';
require APP_ROOT . '/src/spreadsheet.php';

// Liste yükleme: 1) dosya seç  2) sütunları eşle + önizle  3) onayla.
// Satır verisi istemciden ALINMAZ: onayda dosya sunucuda tekrar okunur.
$user = require_perm('import');
$dir = APP_ROOT . '/storage/imports';

/** Oturumdaki yükleme (yoksa null). */
function pending_import(string $dir): ?array
{
    $imp = $_SESSION['import'] ?? null;
    if (!is_array($imp) || !preg_match('/^[a-f0-9]{32}\.(xlsx|csv)$/', (string) ($imp['file'] ?? '')) || !is_file("$dir/{$imp['file']}")) {
        unset($_SESSION['import']);
        return null;
    }
    return $imp;
}

function drop_import(string $dir): void
{
    $imp = $_SESSION['import'] ?? null;
    if (is_array($imp) && preg_match('/^[a-f0-9]{32}\.(xlsx|csv)$/', (string) ($imp['file'] ?? ''))) {
        @unlink("$dir/{$imp['file']}");
    }
    unset($_SESSION['import']);
}

if (is_post()) {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'cancel') {
        drop_import($dir);
        redirect('admin/import.php');
    }

    if ($action === 'upload') {
        drop_import($dir);
        $f = $_FILES['file'] ?? null;
        $ext = upload_ext($f);
        if ($problem = upload_problem($f)) {
            flash('error', $problem);
        } else {
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            foreach (array_merge(glob("$dir/*.xlsx") ?: [], glob("$dir/*.csv") ?: []) as $old) {
                if (filemtime($old) < time() - 86400) {
                    @unlink($old); // yarım kalmış eski yüklemeler
                }
            }
            $name = bin2hex(random_bytes(16)) . '.' . $ext;
            move_uploaded_file($f['tmp_name'], "$dir/$name");
            try {
                read_sheet("$dir/$name", $ext); // bozuk dosyayı hemen yakala
                $_SESSION['import'] = ['file' => $name, 'ext' => $ext, 'original' => mb_substr(basename((string) $f['name']), 0, 200)];
                redirect('admin/import.php?step=preview');
            } catch (RuntimeException $e) {
                @unlink("$dir/$name");
                flash('error', $e->getMessage());
            }
        }
        redirect('admin/import.php');
    }

    if ($action === 'confirm') {
        $imp = pending_import($dir) ?? redirect('admin/import.php');
        $sheet = read_sheet("$dir/{$imp['file']}", $imp['ext']);

        $mapping = [];
        foreach ($sheet['header'] as $i => $h) {
            $m = $_POST['map'][$i] ?? 'ignore';
            // kişisel veri sütunu formdan ne gelirse gelsin alınmaz
            $mapping[$i] = is_personal_header($h) || !isset(IMPORT_FIELDS[$m]) ? 'ignore' : $m;
        }
        $counts = array_count_values($mapping);
        $active = active_event();
        $mode = ($_POST['mode'] ?? '') === 'append' && $active ? 'append' : 'new';
        $eventName = clean_text($_POST['event_name'] ?? '', 160);
        $fixedGroup = clean_text($_POST['fixed_group'] ?? '', 120);

        $problem = match (true) {
            !isset($counts['full_name']) && !isset($counts['first_name']) => 'Bir sütunu "Unvan / Ad Soyad" (ya da "Ad") olarak seçin.',
            ($counts['full_name'] ?? 0) > 1 => '"Unvan / Ad Soyad" sadece bir sütuna verilebilir.',
            $mode === 'new' && $eventName === null => 'Yeni liste için bir ad yazın.',
            default => null,
        };
        if ($problem) {
            flash('error', $problem);
            redirect('admin/import.php?step=preview');
        }

        $built = build_people($sheet['header'], $sheet['rows'], $mapping, $fixedGroup);
        if (!$built['people']) {
            flash('error', 'Eklenecek kişi bulunamadı (ad sütunu boş).');
            redirect('admin/import.php?step=preview');
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            if ($mode === 'new') {
                // create_event() kendi transaction'ını açtığı için burada elle: hepsi tek seferde ya hiç
                q('INSERT INTO events (name, is_active, created_by, created_at) VALUES (?, 0, ?, UTC_TIMESTAMP())', [$eventName, $user['id']]);
                $eventId = (int) $pdo->lastInsertId();
                q('UPDATE events SET is_active = 0 WHERE is_active = 1');
                q('UPDATE events SET is_active = 1 WHERE id = ?', [$eventId]);
            } else {
                $eventId = $active['id'];
            }
            q('INSERT INTO import_batches (event_id, filename, row_count, skipped_count, user_id, created_at) VALUES (?, ?, 0, 0, ?, UTC_TIMESTAMP())',
                [$eventId, $imp['original'], $user['id']]);
            $batchId = (int) $pdo->lastInsertId();
            foreach ($built['people'] as $person) {
                insert_person($eventId, $batchId, $person);
            }
            q('UPDATE import_batches SET row_count = ?, skipped_count = ? WHERE id = ?', [count($built['people']), $built['skipped'], $batchId]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('import: ' . $e->getMessage());
            flash('error', 'Kayıt sırasında hata oldu, hiçbir şey eklenmedi. ' . (config('debug') ? $e->getMessage() : ''));
            redirect('admin/import.php?step=preview');
        }

        audit('import', $imp['original'], [
            'liste' => $mode === 'new' ? $eventName : $active['name'],
            'mod' => $mode === 'new' ? 'yeni liste' : 'aktif listeye ekle',
            'eklenen' => count($built['people']), 'atlanan' => $built['skipped'],
            'alınan sütunlar' => array_values(array_map(fn($i) => $sheet['header'][$i], array_keys(array_filter($mapping, fn($m) => $m !== 'ignore')))),
        ]);
        drop_import($dir);
        flash('success', count($built['people']) . ' kişi eklendi' . ($built['skipped'] ? ", {$built['skipped']} satır adı boş olduğu için atlandı" : '') . '.');
        redirect('admin/index.php');
    }
    redirect('admin/import.php');
}

/* ---------------- Ekran ---------------- */

$imp = ($_GET['step'] ?? '') === 'preview' ? pending_import($dir) : null;
$active = active_event();
render_header('Liste yükle', 'import', ['admin.css']);
?>
<main class="container stack">
<?php if (!$imp): ?>
  <section class="card">
    <h1>Liste yükle</h1>
    <p class="muted">Excel (.xlsx) ya da .csv. İlk sayfa okunur, ilk dolu satır başlık sayılır. En fazla 5 MB, <?= e(number_format(IMPORT_MAX_ROWS, 0, ',', '.')) ?> satır.</p>
    <form method="post" enctype="multipart/form-data" class="stack">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="upload">
      <input type="hidden" name="MAX_FILE_SIZE" value="<?= IMPORT_MAX_BYTES ?>">
      <label class="field">
        <span>Dosya</span>
        <input class="input" type="file" name="file" accept=".xlsx,.csv" required>
        <small>Sonraki adımda sütunları kontrol edip onaylarsınız; bu adımda hiçbir şey kaydedilmez.</small>
      </label>
      <button type="submit" class="btn btn-primary">Devam</button>
    </form>
  </section>
<?php else:
    try {
        $sheet = read_sheet("$dir/{$imp['file']}", $imp['ext']);
    } catch (RuntimeException $e) {
        drop_import($dir);
        echo '<div class="alert alert-error">' . e($e->getMessage()) . '</div></main>';
        render_footer();
        exit;
    }
    $mapping = array_map('guess_field', $sheet['header']);
    $group = sheet_title_to_group($sheet['title']);
    $built = build_people($sheet['header'], $sheet['rows'], $mapping, $group);
    $shown = array_keys(array_filter($mapping, fn($m) => $m !== 'ignore'));
    $defaultName = ($group ?? pathinfo($imp['original'], PATHINFO_FILENAME)) . ' — ' . (new DateTimeImmutable('now', local_tz()))->format('d.m.Y');
?>
  <form method="post" class="stack">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="confirm">

    <section class="card">
      <h1>Kontrol et ve onayla</h1>
      <p class="muted"><?= e($imp['original']) ?> · sayfa “<?= e($sheet['title']) ?>”</p>
      <div class="stats">
        <div class="stat"><div class="stat-label">Eklenecek</div><div class="stat-value"><?= e($built['people'] ? count($built['people']) : 0) ?></div></div>
        <div class="stat"><div class="stat-label">Adı boş (atlanır)</div><div class="stat-value"><?= e($built['skipped']) ?></div></div>
        <div class="stat"><div class="stat-label">Boş satır</div><div class="stat-value"><?= e($sheet['empty_rows']) ?></div></div>
        <div class="stat"><div class="stat-label">Aynı isim</div><div class="stat-value"><?= e($built['duplicates']) ?></div></div>
      </div>
      <?php if ($built['duplicates']): ?><div class="alert alert-warning" style="margin-top:12px">Dosyada aynı isimle <?= e($built['duplicates']) ?> tekrar var. Hepsi ayrı kişi olarak eklenir.</div><?php endif; ?>
      <?php if ($sheet['formulas']): ?><div class="alert alert-warning" style="margin-top:12px"><?= e($sheet['formulas']) ?> hücrede formül var; Excel'in kaydettiği sonuç kullanılır.</div><?php endif; ?>
      <?php if ($sheet['truncated_cols']): ?><div class="alert alert-warning" style="margin-top:12px">İlk <?= IMPORT_MAX_COLS ?> sütun okundu, gerisi alınmadı.</div><?php endif; ?>
    </section>

    <section class="card">
      <h2>Sütunlar</h2>
      <p class="muted">Telefon, e-posta, adres, ortaklar gibi kişisel bilgiler varsayılan olarak <strong>alınmaz</strong>. Yetkili sütunundaki doğum tarihi ve yeri otomatik silinir.</p>
      <div class="map-grid">
        <?php foreach ($sheet['header'] as $i => $h): ?>
          <label class="field map-row">
            <span><?= e($h) ?></span>
            <?php if (is_personal_header($h)): ?>
              <select class="input" disabled><option>Alınmaz — kişisel veri</option></select>
            <?php else: ?>
            <select class="input" name="map[<?= e($i) ?>]">
              <?php foreach (IMPORT_FIELDS as $k => $label): ?>
                <option value="<?= e($k) ?>"<?= $mapping[$i] === $k ? ' selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
            <?php endif; ?>
          </label>
        <?php endforeach; ?>
      </div>
      <label class="field">
        <span>Grup (sabit)</span>
        <input class="input" type="text" name="fixed_group" value="<?= e($group ?? '') ?>" maxlength="120">
        <small>Grup sütunu yoksa ya da hücre boşsa herkese bu yazılır. Boş bırakırsanız grupsuz kalır.</small>
      </label>
    </section>

    <section class="card">
      <h2>Önizleme (ilk 20 satır)</h2>
      <p class="muted">Sadece alınacak sütunlar gösteriliyor. Eşlemeyi değiştirirseniz kayıtta yeni eşleme kullanılır.</p>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><?php foreach ($shown as $i): ?><th><?= e($sheet['header'][$i]) ?></th><?php endforeach; ?></tr></thead>
          <tbody>
          <?php foreach (array_slice($sheet['rows'], 0, 20) as $row): ?>
            <tr><?php foreach ($shown as $i): ?><td><?= e($mapping[$i] === 'authority' ? names_only($row[$i] ?? null) : ($row[$i] ?? '')) ?></td><?php endforeach; ?></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>

    <section class="card">
      <h2>Nereye eklensin?</h2>
      <?php if ($active): ?>
        <label class="radio"><input type="radio" name="mode" value="new" checked> <span><strong>Yeni liste başlat</strong> — “<?= e($active['name']) ?>” arşive alınır, silinmez; işaretleri korunur.</span></label>
        <label class="radio"><input type="radio" name="mode" value="append"> <span><strong>Aktif listeye ekle</strong> — “<?= e($active['name']) ?>” listesine eklenir (başka bir grubun dosyası gibi).</span></label>
      <?php else: ?>
        <input type="hidden" name="mode" value="new">
      <?php endif; ?>
      <label class="field" style="margin-top:12px">
        <span>Yeni liste adı</span>
        <input class="input" type="text" name="event_name" value="<?= e($defaultName) ?>" maxlength="160">
      </label>
      <div class="row">
        <button type="submit" class="btn btn-primary btn-lg">Onayla ve kaydet</button>
        <button type="submit" class="btn btn-ghost" form="cancel-form">Vazgeç</button>
      </div>
    </section>
  </form>
  <form method="post" id="cancel-form"><?= csrf_field() ?><input type="hidden" name="action" value="cancel"></form>
<?php endif; ?>
</main>
<?php render_footer(); ?>
