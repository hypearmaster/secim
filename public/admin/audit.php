<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

// Denetim kaydı: kim, ne zaman, hangi yönetim işlemini yaptı. Sadece admin.
$user = require_perm('view_audit');

$filterAction = isset(AUDIT_ACTIONS[$_GET['action'] ?? '']) ? $_GET['action'] : '';
$filterUser = (int) ($_GET['user_id'] ?? 0);
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;

$where = '1 = 1';
$params = [];
if ($filterAction !== '') {
    $where .= ' AND a.action = ?';
    $params[] = $filterAction;
}
if ($filterUser) {
    $where .= ' AND a.user_id = ?';
    $params[] = $filterUser;
}
$total = (int) q("SELECT COUNT(*) FROM audit_log a WHERE $where", $params)->fetchColumn();
$offset = ($page - 1) * $perPage;
$rows = q("SELECT a.*, u.username, u.full_name FROM audit_log a LEFT JOIN users u ON u.id = a.user_id
           WHERE $where ORDER BY a.id DESC LIMIT $perPage OFFSET $offset", $params)->fetchAll();
$users = q('SELECT id, username, full_name FROM users ORDER BY full_name')->fetchAll();
$pages = max(1, (int) ceil($total / $perPage));

$danger = ['login_failed', 'login_locked', 'user_deactivate', 'supporters_clear'];
$watch = ['export', 'user_password', 'user_create', 'user_update', 'import', 'event_activate'];

render_header('Denetim kaydı', 'audit', ['admin.css']);
?>
<main class="container stack">
  <section class="card">
    <h1>Denetim kaydı</h1>
    <p class="muted">Giriş/çıkış, kullanıcı, liste, dışa aktarma ve Bizimkiler işlemleri. Şifreler hiçbir zaman kaydedilmez. GELDİ işaretleri “Kayıtlar” sayfasında.</p>
    <form method="get" class="row" style="align-items:flex-end">
      <label class="field" style="margin:0;min-width:180px"><span>İşlem</span>
        <select class="input" name="action">
          <option value="">Tümü</option>
          <?php foreach (AUDIT_ACTIONS as $k => $lbl): ?>
            <option value="<?= e($k) ?>"<?= $filterAction === $k ? ' selected' : '' ?>><?= e($lbl) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="field" style="margin:0;min-width:180px"><span>Kullanıcı</span>
        <select class="input" name="user_id">
          <option value="">Tümü</option>
          <?php foreach ($users as $u): ?>
            <option value="<?= e($u['id']) ?>"<?= $filterUser === (int) $u['id'] ? ' selected' : '' ?>><?= e($u['full_name'] . ' (' . $u['username'] . ')') ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <button class="btn" type="submit">Filtrele</button>
      <?php if ($filterAction || $filterUser): ?><a class="btn btn-ghost" href="<?= e(url('admin/audit.php')) ?>">Temizle</a><?php endif; ?>
    </form>
  </section>

  <section class="card">
    <h2><?= e($total) ?> kayıt</h2>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>Zaman</th><th>Kullanıcı</th><th>İşlem</th><th>Konu</th><th>Ayrıntı</th><th>IP</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r):
            $detail = $r['detail'] ? (json_decode($r['detail'], true) ?: []) : [];
            $cls = in_array($r['action'], $danger, true) ? 'badge-danger' : (in_array($r['action'], $watch, true) ? 'badge-warning' : '');
        ?>
          <tr>
            <td style="white-space:nowrap"><?= e(fmt_datetime($r['created_at'])) ?></td>
            <td><?= $r['username'] ? e($r['full_name']) . ' <span class="muted">(' . e($r['username']) . ')</span>' : '<span class="muted">—</span>' ?></td>
            <td><span class="badge <?= e($cls) ?>"><?= e(AUDIT_ACTIONS[$r['action']] ?? $r['action']) ?></span></td>
            <td><?= e($r['target'] ?? '') ?></td>
            <td class="audit-detail">
              <?php foreach ($detail as $k => $v): ?>
                <div><span class="muted"><?= e($k) ?>:</span> <?= e(is_array($v) ? (array_is_list($v) ? implode(', ', $v) : json_encode($v, JSON_UNESCAPED_UNICODE)) : (string) $v) ?></div>
              <?php endforeach; ?>
            </td>
            <td class="muted"><?= e(ip_to_text($r['ip'])) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="6" class="empty">Kayıt yok.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php if ($pages > 1): ?>
      <nav class="row" style="justify-content:center;margin-top:12px">
        <?php for ($p = max(1, $page - 5); $p <= min($pages, $page + 5); $p++):
            $qs = http_build_query(array_filter(['action' => $filterAction, 'user_id' => $filterUser ?: null, 'page' => $p])); ?>
          <a class="btn btn-sm<?= $p === $page ? '' : ' btn-ghost' ?>" href="<?= e(url('admin/audit.php?' . $qs)) ?>"><?= $p ?></a>
        <?php endfor; ?>
      </nav>
    <?php endif; ?>
  </section>
</main>
<?php render_footer(); ?>
