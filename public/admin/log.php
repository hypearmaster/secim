<?php
require __DIR__ . '/../../src/bootstrap.php';

$user = require_perm('view_log');

$canViewAll = can('view_all', $user);
$activeEvent = active_event();

$filterUser = (int) ($_GET['user_id'] ?? 0);
$filterAction = $_GET['action'] ?? '';
$filterRegion = $_GET['region'] ?? '';
$filterEvent = (int) ($_GET['event_id'] ?? ($activeEvent['id'] ?? 0));

if ($canViewAll) {
    $eventOptions = q('SELECT id, name FROM events ORDER BY created_at DESC')->fetchAll();
} else {
    $eventOptions = $activeEvent ? [['id' => $activeEvent['id'], 'name' => $activeEvent['name']]] : [];
}

$where = '1=1';
$params = [];

if ($filterEvent) {
    $where .= ' AND al.event_id = ?';
    $params[] = $filterEvent;
} elseif ($activeEvent) {
    $where .= ' AND al.event_id = ?';
    $params[] = $activeEvent['id'];
} else {
    $where .= ' AND 1=0';
}

if ($filterUser) {
    $where .= ' AND al.user_id = ?';
    $params[] = $filterUser;
}

if ($filterAction !== '') {
    $where .= ' AND al.action = ?';
    $params[] = $filterAction;
}

if ($filterRegion !== '') {
    $where .= ' AND p.region = ?';
    $params[] = $filterRegion;
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

$total = (int) q("
    SELECT COUNT(*)
    FROM attendance_log al
    JOIN people p ON p.id = al.person_id
    WHERE $where
", $params)->fetchColumn();

$logs = q("
    SELECT al.*, p.full_name AS person_name, p.region, u.username, u.full_name AS user_name, u.role AS user_role
    FROM attendance_log al
    JOIN people p ON p.id = al.person_id
    JOIN users u ON u.id = al.user_id
    WHERE $where
    ORDER BY al.created_at DESC
    LIMIT ? OFFSET ?
", [...$params, $perPage, $offset])->fetchAll();

$totalPages = max(1, (int) ceil($total / $perPage));

$usersForFilter = q('SELECT id, username, full_name FROM users WHERE is_active = 1 ORDER BY full_name')->fetchAll();
$regionsForFilter = q("SELECT DISTINCT region FROM people WHERE region IS NOT NULL AND region <> '' ORDER BY region")->fetchAll(PDO::FETCH_COLUMN);

$baseQuery = $_GET;
unset($baseQuery['page']);

render_header('İşlem Kayıtları', 'log', ['admin.css']);
?>
<main class="container">
<div class="stack">
    <section class="card">
        <h2>Filtreler</h2>
        <form method="get" class="row" style="flex-wrap: wrap; gap: 8px; align-items: flex-end;">
            <?php if ($canViewAll && count($eventOptions) > 1): ?>
            <div class="field" style="margin-bottom: 0; min-width: 180px;">
                <span>Etkinlik</span>
                <select class="input" name="event_id">
                    <?php foreach ($eventOptions as $eo): ?>
                        <option value="<?= e($eo['id']) ?>" <?= $filterEvent === $eo['id'] ? 'selected' : '' ?>><?= e($eo['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="field" style="margin-bottom: 0; min-width: 180px;">
                <span>Kullanıcı</span>
                <select class="input" name="user_id">
                    <option value="">Tümü</option>
                    <?php foreach ($usersForFilter as $uf): ?>
                        <option value="<?= e($uf['id']) ?>" <?= $filterUser === $uf['id'] ? 'selected' : '' ?>><?= e($uf['full_name'] . ' (' . $uf['username'] . ')') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field" style="margin-bottom: 0; min-width: 160px;">
                <span>İşlem</span>
                <select class="input" name="action">
                    <option value="">Tümü</option>
                    <option value="mark" <?= $filterAction === 'mark' ? 'selected' : '' ?>>Geldi</option>
                    <option value="unmark" <?= $filterAction === 'unmark' ? 'selected' : '' ?>>Geri alındı</option>
                    <option value="stance" <?= $filterAction === 'stance' ? 'selected' : '' ?>>Durum değiştirildi</option>
                </select>
            </div>
            <div class="field" style="margin-bottom: 0; min-width: 160px;">
                <span>İlçe</span>
                <select class="input" name="region">
                    <option value="">Tümü</option>
                    <?php foreach ($regionsForFilter as $reg): ?>
                        <option value="<?= e($reg) ?>" <?= $filterRegion === $reg ? 'selected' : '' ?>><?= e($reg) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="display: flex; gap: 4px; align-items: flex-end;">
                <button type="submit" class="btn">Filtrele</button>
                <?php if ($filterUser || $filterAction || $filterRegion || ($canViewAll && $filterEvent)): ?>
                    <a class="btn btn-ghost" href="<?= e(url('admin/log.php')) ?>">Temizle</a>
                <?php endif; ?>
            </div>
        </form>
    </section>

    <section class="card">
        <h2>İşlem Kayıtları (<?= e($total) ?>)</h2>
        <?php if ($logs): ?>
        <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Saat</th>
                    <th>İşlem</th>
                    <th>Unvan</th>
                    <th>İlçe</th>
                    <th>Kullanıcı</th>
                    <?php if ($user['role'] === 'admin'): ?>
                        <th>IP</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($logs as $log): ?>
                <tr>
                    <td><?= e(fmt_datetime($log['created_at'])) ?></td>
                    <td>
                        <?php if ($log['action'] === 'stance'): ?>
                            <span class="badge badge-<?= e(stance_view($log['note'])['color']) ?>">
                                <?= e('Durum: ' . stance_view($log['note'])['label']) ?>
                            </span>
                        <?php else: ?>
                            <span class="badge badge-<?= $log['action'] === 'mark' ? 'success' : 'danger' ?>">
                                <?= e($log['action'] === 'mark' ? 'Geldi' : 'Geri alındı') ?>
                            </span>
                        <?php endif; ?>
                    </td>
                    <td><?= e($log['person_name']) ?></td>
                    <td><?= e($log['region'] ?? '—') ?></td>
                    <td><?= e($log['user_name']) ?> <span class="badge badge-role"><?= e(ROLES[$log['user_role']]) ?></span></td>
                    <?php if ($user['role'] === 'admin'): ?>
                        <td><?= e(ip_to_text($log['ip'])) ?></td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>

        <?php if ($totalPages > 1): ?>
        <nav class="row" style="justify-content: center; margin-top: 12px;">
            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                <?php
                $baseQuery['page'] = $p;
                $href = url('admin/log.php?' . http_build_query($baseQuery));
                $cls = $p === $page ? 'btn' : 'btn btn-ghost';
                ?>
                <a class="<?= $cls ?>" href="<?= e($href) ?>"><?= $p ?></a>
            <?php endfor; ?>
        </nav>
        <?php endif; ?>
        <?php else: ?>
        <p class="muted">Kayıt yok.</p>
        <?php endif; ?>
    </section>
</div>
</main>
<?php
render_footer();