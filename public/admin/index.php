<?php
require __DIR__ . '/../../src/bootstrap.php';
require APP_ROOT . '/src/votes.php';
require APP_ROOT . '/src/dashboard.php';

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
$d = dashboard_data($user, $eventId);
$stats = $d['stats'];

/** Kırılım tablosu satırı (ilk çizim; arka plan yenilemesi aynı yapıyı dashboard-live.js ile üretir). */
function dash_row(array $r): void
{ ?>
                <tr>
                    <td><?= e($r['label']) ?></td>
                    <td class="num"><?= e($r['total']) ?></td>
                    <td class="num"><?= e($r['came']) ?></td>
                    <td class="num"><?= e($r['rate']) ?>%</td>
                    <td class="col-bar">
                        <div class="progress" style="width: 100%; max-width: 200px;">
                            <span style="width: <?= e($r['rate']) ?>%"></span>
                        </div>
                    </td>
                </tr>
<?php }

/** İlçe / Grup kırılımı kartı. */
function dash_breakdown(string $key, string $title, string $first, array $rows): void
{ ?>
    <section class="card">
        <h2><?= e($title) ?></h2>
        <?php if ($rows): ?>
        <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th><?= e($first) ?></th>
                    <th class="num">Toplam</th>
                    <th class="num">Gelen</th>
                    <th class="num">Oran</th>
                    <th class="col-bar">Grafik</th>
                </tr>
            </thead>
            <tbody data-dash="<?= e($key) ?>">
            <?php foreach ($rows as $r) dash_row($r); ?>
            </tbody>
        </table>
        </div>
        <?php else: ?>
        <p class="muted">Veri yok.</p>
        <?php endif; ?>
    </section>
<?php }
?>
<main class="container" id="dash" data-api="<?= e(url('api/dashboard.php')) ?>" data-event="<?= e($eventId) ?>">
<div class="stats">
    <div class="stat">
        <div class="stat-label">Toplam</div>
        <div class="stat-value" data-stat="total"><?= e($stats['total']) ?></div>
    </div>
    <div class="stat">
        <div class="stat-label">Gelen</div>
        <div class="stat-value" data-stat="came"><?= e($stats['came']) ?></div>
    </div>
    <div class="stat">
        <div class="stat-label">Bekleyen</div>
        <div class="stat-value" data-stat="pending"><?= e($stats['pending']) ?></div>
    </div>
    <div class="stat">
        <div class="stat-label">Oran</div>
        <div class="stat-value"><span data-stat="rate"><?= e($stats['rate']) ?></span>%</div>
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
    <section class="card teams" id="teams" data-teams="<?= e(json_encode($d['teams'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>">
        <div class="teams-head">
            <div><span class="stat-label">Kimin adamı</span><h2>Takımlar</h2></div>
            <span class="muted teams-note">Bir kişi en fazla <span data-team="max"><?= e($d['teams']['summary']['max']) ?></span> takımda</span>
        </div>
        <div class="teams-sum">
            <div><strong data-team="owners"><?= e($d['teams']['summary']['owners']) ?></strong><span>takım kuran</span></div>
            <div><strong data-team="owned"><?= e($d['teams']['summary']['owned']) ?></strong><span>takımda firma</span></div>
            <div><strong data-team="owned_came"><?= e($d['teams']['summary']['owned_came']) ?></strong><span>takımdan gelen</span></div>
            <div><strong data-team="full"><?= e($d['teams']['summary']['full']) ?></strong><span>dolu (<?= e($d['teams']['summary']['max']) ?>/<?= e($d['teams']['summary']['max']) ?>)</span></div>
            <div class="is-warn"><strong data-team="unowned"><?= e($d['teams']['summary']['unowned']) ?></strong><span>sahipsiz</span></div>
        </div>
        <div class="teams-list" id="teams-list"></div>
        <p class="muted" id="teams-empty" hidden>Henüz kimse takım kurmadı. Onay ekranında kartlardaki “+ Takımıma al” ile eklenir.</p>
    </section>
    <?php dash_breakdown('region', 'İlçe Kırılımı', 'İlçe', $d['region']); ?>
    <?php dash_breakdown('group', 'Grup Kırılımı', 'Grup', $d['group']); ?>

    <?php if ($d['stance']): ?>
    <section class="card">
        <h2>Bizimle mi?</h2>
        <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Durum</th><th class="num">Toplam</th><th class="num">Gelen</th><th class="num">Oran</th></tr></thead>
            <tbody data-dash="stance">
            <?php foreach ($d['stance'] as $sb): ?>
                <tr>
                    <td><span class="badge badge-<?= e($sb['color']) ?>"><?= e($sb['label']) ?></span></td>
                    <td class="num"><?= e($sb['total']) ?></td>
                    <td class="num"><?= e($sb['came']) ?></td>
                    <td class="num"><?= e($sb['rate']) ?>%</td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </section>
    <?php endif; ?>

    <section class="card">
        <h2>Son 10 İşlem</h2>
        <?php if ($d['logs']): ?>
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
            <tbody data-dash="logs">
            <?php foreach ($d['logs'] as $log): ?>
                <tr<?= $log['row'] !== '' ? ' class="' . e($log['row']) . '"' : '' ?>>
                    <td><?= e($log['time']) ?></td>
                    <td><span class="badge badge-<?= e($log['badge']) ?>"><?= e($log['label']) ?></span></td>
                    <td><?= e($log['name']) ?></td>
                    <td><?= e($log['region']) ?></td>
                    <td><?= e($log['user']) ?></td>
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
<?php
render_footer(['live.js', 'dashboard-live.js', 'dashboard-votes.js']);
