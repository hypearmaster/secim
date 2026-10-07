<?php
require __DIR__ . '/../../src/bootstrap.php';

$user = require_perm('manage_events');

if (is_post()) {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    $targetId = (int) ($_POST['id'] ?? 0);

    if ($action === 'create') {
        $name = clean_text($_POST['name'] ?? '', 160);
        if (!$name) {
            flash('error', 'Etkinlik adı zorunlu.');
        } else {
            create_event($name, $user['id'], false);
            flash('success', 'Boş etkinlik oluşturuldu.');
        }
        redirect('admin/events.php');
    }

    if ($targetId) {
        $target = q('SELECT * FROM events WHERE id = ?', [$targetId])->fetch();
        if ($target) {
            if ($action === 'activate') {
                activate_event($targetId);
                flash('success', 'Etkinlik aktif edildi.');
            } elseif ($action === 'rename') {
                $name = clean_text($_POST['name'] ?? '', 160);
                if (!$name) {
                    flash('error', 'Etkinlik adı zorunlu.');
                } else {
                    q('UPDATE events SET name = ? WHERE id = ?', [$name, $targetId]);
                    flash('success', 'Etkinlik yeniden adlandırıldı.');
                }
            }
        }
        redirect('admin/events.php');
    }
}

// Listeleme
$events = q('SELECT e.*, u.username AS created_by_name,
                   (SELECT COUNT(*) FROM people p WHERE p.event_id = e.id) AS person_count,
                   (SELECT COUNT(*) FROM people p WHERE p.event_id = e.id AND p.attended_at IS NOT NULL) AS attended_count
            FROM events e
            LEFT JOIN users u ON u.id = e.created_by
            ORDER BY e.created_at DESC')->fetchAll();

render_header('Etkinlikler', 'events', ['admin.css']);
?>
<main class="container">
<div class="stack">
    <section class="card">
        <h2>Yeni Boş Etkinlik Oluştur</h2>
        <form method="post" class="row" style="gap: 8px; flex-wrap: wrap;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">
            <div class="field" style="flex: 1; min-width: 200px; margin-bottom: 0;">
                <span>Etkinlik Adı</span>
                <input class="input" type="text" name="name" required maxlength="160" placeholder="Örn. Grup 10 - Ekim 2026">
            </div>
            <div style="align-self: flex-end;">
                <button type="submit" class="btn">Oluştur</button>
            </div>
        </form>
    </section>

    <section class="card">
        <h2>Etkinlik Listesi</h2>
        <?php if ($events): ?>
        <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Ad</th>
                    <th>Oluşturma</th>
                    <th>Kişi Sayısı</th>
                    <th>Gelen</th>
                    <th>Durum</th>
                    <th>Oluşturan</th>
                    <th>İşlemler</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($events as $ev): ?>
                <tr>
                    <td><?= e($ev['name']) ?></td>
                    <td><?= e(fmt_datetime($ev['created_at'])) ?></td>
                    <td class="num"><?= e($ev['person_count']) ?></td>
                    <td class="num"><?= e($ev['attended_count']) ?></td>
                    <td>
                        <?php if ($ev['is_active']): ?>
                            <span class="badge badge-success">Aktif</span>
                        <?php else: ?>
                            <span class="badge badge-muted">Pasif</span>
                        <?php endif; ?>
                    </td>
                    <td><?= e($ev['created_by_name'] ?? '—') ?></td>
                    <td>
                        <div class="row" style="gap: 4px;">
                            <?php if (!$ev['is_active']): ?>
                                <form method="post" class="inline" onsubmit="return confirm('Bu etkinlik aktif edilecek, diğerleri pasife alınacak. Emin misiniz?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="activate">
                                    <input type="hidden" name="id" value="<?= e($ev['id']) ?>">
                                    <button type="submit" class="btn btn-sm btn-success">Aktif Yap</button>
                                </form>
                            <?php endif; ?>
                            <form method="post" class="inline" onsubmit="return confirm('Etkinlik adı değiştirilsin mi?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="rename">
                                <input type="hidden" name="id" value="<?= e($ev['id']) ?>">
                                <input class="input" type="text" name="name" value="<?= e($ev['name']) ?>" maxlength="160" style="width: 180px; min-height: 36px; padding: 6px 10px;">
                                <button type="submit" class="btn btn-sm">Kaydet</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php else: ?>
        <p class="muted">Henüz etkinlik yok.</p>
        <?php endif; ?>
    </section>
</div>
</main>
<?php
render_footer();