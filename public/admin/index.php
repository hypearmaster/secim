<?php
require __DIR__ . '/../../src/bootstrap.php';
require APP_ROOT . '/src/votes.php';

$user = require_perm('dashboard');
$event = active_event();

render_header('Yönetim Paneli', 'dashboard', ['admin.css']);

if (!$event) {
    echo '<main class="container">';
    echo '<div class="card"><h1>Aktif etkinlik yok</h1>';
    echo '<p>Henüz aktif bir etkinlik bulunmuyor. Önce bir liste yükleyin.</p>';
    echo '<a class="btn" href="' . e(url('admin/import.php')) . '">Liste yükle</a></div>';
    echo '</main>';
    render_footer();
    exit;
}

$eventId = (int) $event['id'];

$stats = scope_stats($user);
$regionBreakdown = people_breakdown($user, 'region', $eventId);
$groupBreakdown = people_breakdown($user, 'group_name', $eventId);
$stanceBreakdown = [];
if (can('stance', $user)) {
    // Firma bazında (şubeler tek): onay ekranı ve Bizimkiler ile tutarlı olsun — şube şişmesi olmasın
    foreach (q('SELECT stance, COUNT(DISTINCT firm_key) AS total,
                       COUNT(DISTINCT CASE WHEN attended_at IS NOT NULL THEN firm_key END) AS came
                FROM people WHERE event_id = ? GROUP BY stance ORDER BY stance IS NULL, stance', [$eventId])->fetchAll() as $r) {
        $sv = stance_view($r['stance']);
        $stanceBreakdown[] = ['label' => $sv['label'], 'color' => $sv['color'], 'total' => (int) $r['total'], 'came' => (int) $r['came']];
    }
}

$logSql = "SELECT al.*, p.full_name AS person_name, p.authority AS person_authority, p.region, p.stance AS person_stance, u.username, u.full_name AS user_name
           FROM attendance_log al
           JOIN people p ON p.id = al.person_id
           JOIN users u ON u.id = al.user_id
           WHERE al.event_id = ?
           ORDER BY al.created_at DESC
           LIMIT 10";
$recentLogs = q($logSql, [$eventId])->fetchAll();
?>
<main class="container">
<div class="stats">
    <div class="stat">
        <div class="stat-label">Toplam</div>
        <div class="stat-value"><?= e($stats['total']) ?></div>
    </div>
    <div class="stat">
        <div class="stat-label">Gelen</div>
        <div class="stat-value"><?= e($stats['came']) ?></div>
    </div>
    <div class="stat">
        <div class="stat-label">Bekleyen</div>
        <div class="stat-value"><?= e($stats['total'] - $stats['came']) ?></div>
    </div>
    <div class="stat">
        <div class="stat-label">Oran</div>
        <div class="stat-value"><?= $stats['total'] ? round($stats['came'] / $stats['total'] * 100) : 0 ?>%</div>
    </div>
</div>

<div class="stack">
    <section class="card vote-summary" id="vote-summary" data-api="<?= e(url('api/vote.php')) ?>">
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
        <div class="vote-summary-footer"><span class="muted" id="vote-updated">Son kontrol bekleniyor</span><span class="muted">Saniyelik takip · Sayım devam ediyor</span></div>
    </section>
    <section class="card">
        <h2>İlçe Kırılımı</h2>
        <?php if ($regionBreakdown): ?>
        <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>İlçe</th>
                    <th class="num">Toplam</th>
                    <th class="num">Gelen</th>
                    <th class="num">Oran</th>
                    <th class="col-bar">Grafik</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($regionBreakdown as $rb): ?>
                <tr>
                    <td><?= e($rb['label'] ?? '—') ?></td>
                    <td class="num"><?= e($rb['total']) ?></td>
                    <td class="num"><?= e($rb['came']) ?></td>
                    <td class="num"><?= $rb['total'] ? round($rb['came'] / $rb['total'] * 100) : 0 ?>%</td>
                    <td class="col-bar">
                        <div class="progress" style="width: 100%; max-width: 200px;">
                            <span style="width: <?= $rb['total'] ? round($rb['came'] / $rb['total'] * 100) : 0 ?>%"></span>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php else: ?>
        <p class="muted">Veri yok.</p>
        <?php endif; ?>
    </section>

    <section class="card">
        <h2>Grup Kırılımı</h2>
        <?php if ($groupBreakdown): ?>
        <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Grup</th>
                    <th class="num">Toplam</th>
                    <th class="num">Gelen</th>
                    <th class="num">Oran</th>
                    <th class="col-bar">Grafik</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($groupBreakdown as $gb): ?>
                <tr>
                    <td><?= e($gb['label'] ?? '—') ?></td>
                    <td class="num"><?= e($gb['total']) ?></td>
                    <td class="num"><?= e($gb['came']) ?></td>
                    <td class="num"><?= $gb['total'] ? round($gb['came'] / $gb['total'] * 100) : 0 ?>%</td>
                    <td class="col-bar">
                        <div class="progress" style="width: 100%; max-width: 200px;">
                            <span style="width: <?= $gb['total'] ? round($gb['came'] / $gb['total'] * 100) : 0 ?>%"></span>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php else: ?>
        <p class="muted">Veri yok.</p>
        <?php endif; ?>
    </section>

    <?php if ($stanceBreakdown): ?>
    <section class="card">
        <h2>Bizimle mi?</h2>
        <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Durum</th><th class="num">Toplam</th><th class="num">Gelen</th><th class="num">Oran</th></tr></thead>
            <tbody>
            <?php foreach ($stanceBreakdown as $sb): ?>
                <tr>
                    <td><span class="badge badge-<?= e($sb['color']) ?>"><?= e($sb['label']) ?></span></td>
                    <td class="num"><?= e($sb['total']) ?></td>
                    <td class="num"><?= e($sb['came']) ?></td>
                    <td class="num"><?= $sb['total'] ? round($sb['came'] / $sb['total'] * 100) : 0 ?>%</td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </section>
    <?php endif; ?>

    <section class="card">
        <h2>Son 10 İşlem</h2>
        <?php if ($recentLogs): ?>
        <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Saat</th>
                    <th>İşlem</th>
                    <th>Unvan</th>
                    <th>İlçe</th>
                    <th>Kullanıcı</th>
                </tr>
            </thead>
            <tbody>
            <?php $bizimle = can('stance', $user) ? (string) config('supporter_stance', 'bizimle') : null; ?>
            <?php foreach ($recentLogs as $log): ?>
                <tr<?= $bizimle !== null && $log['person_stance'] === 'karsi' ? ' class="row-rakip"' : ($bizimle !== null && $log['person_stance'] === $bizimle ? ' class="row-bizimle"' : '') ?>>
                    <td><?= e(fmt_datetime($log['created_at'])) ?></td>
                    <td>
                        <span class="badge badge-<?= $log['action'] === 'mark' ? 'success' : ($log['action'] === 'unmark' ? 'danger' : 'warning') ?>">
                            <?= e($log['action'] === 'mark' ? 'Geldi' : ($log['action'] === 'unmark' ? 'Geri alındı' : 'Durum değiştirildi')) ?>
                        </span>
                    </td>
                    <td><?= e($log['person_authority'] ?: $log['person_name']) ?></td>
                    <td><?= e($log['region'] ?? '—') ?></td>
                    <td><?= e($log['user_name']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php else: ?>
        <p class="muted">Henüz işlem yok.</p>
        <?php endif; ?>
    </section>
</div>
</main>

<script>
(function () {
    // 60 sn'de bir tazele — sadece kullanıcı hâlâ paneldeyse (başka sayfaya geçtiyse dokunma)
    var stamp = Date.now();
    document.body.setAttribute('data-dashboard', stamp);
    setTimeout(function () {
        if (document.body.getAttribute('data-dashboard') !== String(stamp)) return;
        window.secimNav ? window.secimNav.reload() : window.location.reload();
    }, 60000);
})();
</script>
<?php
render_footer(['dashboard-votes.js']);
