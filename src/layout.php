<?php
declare(strict_types=1);

/**
 * Ortak sayfa iskeleti. Kullanım:
 *   render_header('Kullanıcılar', 'users');   ... sayfa içeriği <main class="container"> ...   render_footer();
 * $active: menüde vurgulanacak anahtar (app, dashboard, users, import, export, log, events)
 * $extraCss: public/assets altındaki ek css dosyaları (örn. ['admin.css'])
 */
function render_header(string $title, string $active = '', array $extraCss = []): void
{
    $user = current_user();
    $event = $user ? active_event() : null;
    $appName = (string) config('app_name', 'Katılım Takip');

    $nav = [];
    if ($user) {
        if (can('dashboard', $user)) $nav['dashboard'] = ['admin/index.php', 'Panel'];
        $nav['app'] = ['app.php', 'Onay'];
        if (can('supporters', $user)) $nav['supporters'] = ['admin/supporters.php', 'Bizimkiler'];
        if (can('view_log', $user)) $nav['log'] = ['admin/log.php', 'Kayıtlar'];
        if (can('view_audit', $user)) $nav['audit'] = ['admin/audit.php', 'Denetim'];
        if (can('export', $user)) $nav['export'] = ['admin/export.php', 'Dışa aktar'];
        if (can('import', $user)) $nav['import'] = ['admin/import.php', 'Liste yükle'];
        if (can('manage_events', $user)) $nav['events'] = ['admin/events.php', 'Etkinlikler'];
        if (can('manage_users', $user)) $nav['users'] = ['admin/users.php', 'Kullanıcılar'];
    }
    ?><!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0f172a">
<meta name="robots" content="noindex, nofollow">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<title><?= e($title) ?> · <?= e($appName) ?></title>
<link rel="stylesheet" href="<?= e(asset_url('app.css')) ?>">
<?php foreach ($extraCss as $css): ?>
<link rel="stylesheet" href="<?= e(asset_url($css)) ?>">
<?php endforeach; ?>
</head>
<body>
<?php if ($user): ?>
<header class="topbar">
  <div class="topbar-row">
    <div class="topbar-title">
      <strong><?= e($appName) ?></strong>
      <span class="topbar-event"><?= $event ? e($event['name']) : 'Aktif etkinlik yok' ?></span>
    </div>
    <div class="topbar-user">
      <span class="topbar-name"><?= e($user['full_name']) ?></span>
      <span class="badge badge-role"><?= e(ROLES[$user['role']]) ?><?= $user['region'] ? ' · ' . e($user['region']) : '' ?></span>
      <form method="post" action="<?= e(url('logout.php')) ?>" class="inline">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-ghost btn-sm">Çıkış</button>
      </form>
    </div>
  </div>
  <?php if (count($nav) > 1): ?>
  <nav class="topnav" aria-label="Ana menü">
    <?php foreach ($nav as $key => [$href, $label]): ?>
      <a href="<?= e(url($href)) ?>" class="<?= $key === $active ? 'is-active' : '' ?>"<?= $key === $active ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
  </nav>
  <?php endif; ?>
</header>
<?php endif; ?>
<?php foreach (take_flashes() as $f): ?>
<div class="flash flash-<?= e($f['type']) ?>" role="status"><?= e($f['message']) ?></div>
<?php endforeach; ?>
<?php
}

/** assets/ altındaki dosya + değişiklik tarihi: güncellemede telefonlar eski CSS/JS'i önbellekten kullanmasın. */
function asset_url(string $file): string
{
    $path = APP_ROOT . '/public/assets/' . $file;
    return url('assets/' . $file) . (is_file($path) ? '?v=' . filemtime($path) : '');
}

function render_footer(array $scripts = []): void
{
    foreach ($scripts as $js) {
        echo '<script src="' . e(asset_url($js)) . '" defer></script>' . "\n";
    }
    echo "</body>\n</html>\n";
}
