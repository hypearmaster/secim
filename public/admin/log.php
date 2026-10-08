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
    SELECT al.*, p.full_name AS person_name, p.authority AS person_authority, p.region, p.stance AS person_stance, u.username, u.full_name AS user_name, u.role AS user_role
    FROM attendance_log al
    JOIN people p ON p.id = al.person_id
    JOIN users u ON u.id = al.user_id
    WHERE $where
    ORDER BY al.created_at DESC, al.id DESC
    LIMIT ? OFFSET ?
", [...$params, $perPage, $offset])->fetchAll();

$totalPages = max(1, (int) ceil($total / $perPage));

$usersForFilter = q('SELECT id, username, full_name FROM users WHERE is_active = 1 ORDER BY full_name')->fetchAll();
$regionsForFilter = q("SELECT DISTINCT region FROM people WHERE region IS NOT NULL AND region <> '' ORDER BY region")->fetchAll(PDO::FETCH_COLUMN);

// "Bizimle" satırları mavi (sadece bu bilgiyi görme yetkisi olana)
$bizimle = can('stance', $user) ? (string) config('supporter_stance', 'bizimle') : null;

$baseQuery = $_GET;
unset($baseQuery['page']);

const LOG_ACTIONS = ['mark' => 'Geldi', 'unmark' => 'Geri alındı', 'stance' => 'Bizimle mi? değişti', 'transfer' => 'Transfer değişti'];
const LOG_MONTHS = ['', 'Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];
const LOG_DAYS = ['Pazar', 'Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma', 'Cumartesi'];

/** İşlem rozeti: [metin, renk]. Bizimle mi? / Transfer için yeni değer de yazılır. */
function log_badge(array $log): array
{
    return match ($log['action']) {
        'mark'     => ['Geldi', 'success'],
        'unmark'   => ['Geri alındı', 'danger'],
        'stance'   => ['Bizimle mi? → ' . stance_view($log['note'])['label'], stance_view($log['note'])['color']],
        'transfer' => ['Transfer → ' . transfer_view($log['note'])['label'], transfer_view($log['note'])['color']],
        default    => [(string) $log['action'], 'muted'],
    };
}

/** "8 Ekim 2026 · Perşembe" (yerel saate göre gün). */
function log_day_title(string $utc): string
{
    $d = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
    $d = $d->setTimezone(local_tz());
    return $d->format('j') . ' ' . LOG_MONTHS[(int) $d->format('n')] . ' ' . $d->format('Y') . ' · ' . LOG_DAYS[(int) $d->format('w')];
}

/** Sayfa numaraları: 1 … 4 5 [6] 7 8 … 20 */
function log_pages(int $page, int $totalPages): array
{
    $set = array_unique(array_filter([1, $totalPages, ...range($page - 2, $page + 2)], fn($p) => $p >= 1 && $p <= $totalPages));
    sort($set);
    $out = [];
    $prev = 0;
    foreach ($set as $p) {
        if ($p - $prev > 1) {
            $out[] = null; // boşluk
        }
        $out[] = $p;
        $prev = $p;
    }
    return $out;
}

$pageUrl = fn(int $p) => url('admin/log.php?' . http_build_query([...$baseQuery, 'page' => $p]));

// Güne göre grupla (sıra korunur)
$days = [];
foreach ($logs as $log) {
    $days[log_day_title($log['created_at'])][] = $log;
}

render_header('İşlem Kayıtları', 'log', ['admin.css']);
?>
<main class="container">
<div class="stack">
    <section class="card">
        <h2>Filtreler</h2>
        <form method="get" class="log-filters">
            <?php if ($canViewAll && count($eventOptions) > 1): ?>
            <label class="field">
                <span>Etkinlik</span>
                <select class="input" name="event_id">
                    <?php foreach ($eventOptions as $eo): ?>
                        <option value="<?= e($eo['id']) ?>" <?= $filterEvent === $eo['id'] ? 'selected' : '' ?>><?= e($eo['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php endif; ?>
            <label class="field">
                <span>Kullanıcı</span>
                <select class="input" name="user_id">
                    <option value="">Tümü</option>
                    <?php foreach ($usersForFilter as $uf): ?>
                        <option value="<?= e($uf['id']) ?>" <?= $filterUser === $uf['id'] ? 'selected' : '' ?>><?= e($uf['full_name'] . ' (' . $uf['username'] . ')') ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field">
                <span>İşlem</span>
                <select class="input" name="action">
                    <option value="">Tümü</option>
                    <?php foreach (LOG_ACTIONS as $key => $label): ?>
                        <option value="<?= e($key) ?>" <?= $filterAction === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field">
                <span>İlçe</span>
                <select class="input" name="region">
                    <option value="">Tümü</option>
                    <?php foreach ($regionsForFilter as $reg): ?>
                        <option value="<?= e($reg) ?>" <?= $filterRegion === $reg ? 'selected' : '' ?>><?= e($reg) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="log-filter-actions">
                <button type="submit" class="btn btn-primary">Filtrele</button>
                <?php if ($filterUser || $filterAction || $filterRegion || ($canViewAll && $filterEvent && $filterEvent !== ($activeEvent['id'] ?? 0))): ?>
                    <a class="btn btn-ghost" href="<?= e(url('admin/log.php')) ?>">Temizle</a>
                <?php endif; ?>
            </div>
        </form>
    </section>

    <section class="card">
        <h2>İşlem Kayıtları <span class="muted log-total"><?= e(number_format($total, 0, ',', '.')) ?> kayıt</span></h2>
        <?php if ($logs): ?>
        <div class="log-list">
            <?php foreach ($days as $title => $items): ?>
            <h3 class="log-day"><?= e($title) ?> <span class="muted"><?= e(count($items)) ?></span></h3>
            <?php foreach ($items as $log): [$badgeText, $badgeColor] = log_badge($log); ?>
            <article class="log-row<?= $bizimle !== null && $log['person_stance'] === 'karsi' ? ' is-rakip' : ($bizimle !== null && $log['person_stance'] === $bizimle ? ' is-bizimle' : '') ?>">
                <time class="log-time" datetime="<?= e($log['created_at']) ?>Z"><?= e(fmt_time($log['created_at'])) ?></time>
                <div class="log-who">
                    <strong><?= e($log['person_authority'] ?: $log['person_name']) ?></strong>
                    <?php if ($log['person_authority']): ?><span class="muted"><?= e($log['person_name']) ?></span><?php endif; ?>
                </div>
                <span class="badge badge-<?= e($badgeColor) ?> log-badge"><?= e($badgeText) ?></span>
                <div class="log-meta">
                    <span><?= e($log['user_name']) ?> <span class="badge badge-role"><?= e(ROLES[$log['user_role']]) ?></span></span>
                    <span class="muted"><?= e($log['region'] ?? '—') ?></span>
                    <?php if ($user['role'] === 'admin'): ?><span class="muted log-ip"><?= e(ip_to_text($log['ip'])) ?></span><?php endif; ?>
                </div>
            </article>
            <?php endforeach; ?>
            <?php endforeach; ?>
        </div>

        <?php if ($totalPages > 1): ?>
        <nav class="log-pager" aria-label="Sayfalar">
            <?php if ($page > 1): ?><a class="btn" href="<?= e($pageUrl($page - 1)) ?>" rel="prev">‹ Önceki</a><?php endif; ?>
            <?php foreach (log_pages($page, $totalPages) as $p): ?>
                <?php if ($p === null): ?>
                    <span class="muted" aria-hidden="true">…</span>
                <?php else: ?>
                    <a class="btn<?= $p === $page ? '' : ' btn-ghost' ?>" href="<?= e($pageUrl($p)) ?>"<?= $p === $page ? ' aria-current="page"' : '' ?>><?= $p ?></a>
                <?php endif; ?>
            <?php endforeach; ?>
            <?php if ($page < $totalPages): ?><a class="btn" href="<?= e($pageUrl($page + 1)) ?>" rel="next">Sonraki ›</a><?php endif; ?>
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
