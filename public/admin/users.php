<?php
require __DIR__ . '/../../src/bootstrap.php';

$user = require_perm('manage_users');

if (is_post()) {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    $targetId = (int) ($_POST['id'] ?? 0);

    if ($action === 'create') {
        $username = clean_text($_POST['username'] ?? '', 64);
        $fullName = clean_text($_POST['full_name'] ?? '', 120);
        $role = $_POST['role'] ?? '';
        $region = clean_text($_POST['region'] ?? '', 120) ?: null;
        $pw = (string) ($_POST['new_password'] ?? '');

        if (!$username || !valid_username($username)) {
            flash('error', 'Geçersiz kullanıcı adı.');
        } elseif ($problem = password_problem($pw)) {
            flash('error', $problem);
        } elseif (!$fullName) {
            flash('error', 'Ad soyad zorunlu.');
        } elseif (!isset(ROLES[$role])) {
            flash('error', 'Geçersiz rol.');
        } else {
            $exists = q('SELECT 1 FROM users WHERE username = ?', [$username])->fetch();
            if ($exists) {
                flash('error', 'Bu kullanıcı adı zaten kullanılıyor.');
            } else {
                $hash = password_hash($pw, PASSWORD_DEFAULT);
                q('INSERT INTO users (username, password_hash, full_name, role, region, is_active, created_at) VALUES (?, ?, ?, ?, ?, 1, UTC_TIMESTAMP())', [$username, $hash, $fullName, $role, $region]);
                audit('user_create', $username, ['rol' => ROLES[$role], 'bölge' => $region]);
                flash('success', "Kullanıcı oluşturuldu: $username");
                redirect('admin/users.php');
            }
        }
        // Hata: modal yeniden açılsın, yazılanlar (şifre HARİÇ) dolu gelsin
        $_SESSION['old_user'] = ['username' => $username, 'full_name' => $fullName, 'role' => $role, 'region' => $region];
        redirect('admin/users.php?new=1');
    }

    if ($targetId) {
        $target = q('SELECT * FROM users WHERE id = ?', [$targetId])->fetch();
        if ($target) {
            if ($action === 'toggle_active') {
                if ($targetId === $user['id']) {
                    flash('error', 'Kendinizi pasife alamazsınız.');
                } else {
                    $newActive = (int) $target['is_active'] ? 0 : 1;
                    if ($newActive === 0) {
                        $activeAdmins = (int) q("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1")->fetchColumn();
                        if ($activeAdmins <= 1 && $target['role'] === 'admin') {
                            flash('error', 'Son aktif admin pasife alınamaz.');
                        } else {
                            q('UPDATE users SET is_active = ? WHERE id = ?', [$newActive, $targetId]);
                            revoke_user_sessions($targetId); // kayıtlı cihazları da hemen düşer
                            audit('user_deactivate', $target['username']);
                            // Takımındaki kişiler boşa çıkar (2 kişi sınırında yer tutmasın; aktif olunca geri gelmez)
                            if ($n = team_clear_user($targetId)) {
                                audit('team_clear', $target['username'], ['firma' => $n]);
                            }
                            flash('success', 'Kullanıcı pasife alındı.');
                        }
                    } else {
                        q('UPDATE users SET is_active = ? WHERE id = ?', [$newActive, $targetId]);
                        audit('user_activate', $target['username']);
                        flash('success', 'Kullanıcı aktif edildi.');
                    }
                }
            } elseif ($action === 'revoke_sessions') {
                $n = remember_device_count($targetId);
                revoke_user_sessions($targetId);
                audit('sessions_revoked', $target['username'], ['kayıtlı cihaz' => $n]);
                flash('success', $targetId === $user['id']
                    ? 'Diğer tüm cihazlarınızdan çıkış yapıldı (bu cihaz açık kaldı).'
                    : "{$target['username']} tüm cihazlardan çıkarıldı; tekrar şifreyle girmesi gerekecek.");
            } elseif ($action === 'edit') {
                $newRole = (string) ($_POST['role'] ?? '');
                $newRegion = clean_text($_POST['region'] ?? '', 120) ?: null;
                $newName = clean_text($_POST['full_name'] ?? '', 120);
                $newPw = (string) ($_POST['new_password'] ?? '');
                // Reddedilecek durumlar ÖNCE: hiçbir şey (şifre dahil) yarım değişmesin
                $problem = match (true) {
                    $targetId === $user['id'] && $newRole !== $target['role'] => 'Kendi rolünüzü değiştiremezsiniz.',
                    !isset(ROLES[$newRole]) => 'Geçersiz rol.',
                    !$newName => 'Ad soyad boş olamaz.',
                    $newPw !== '' && password_problem($newPw) !== null => password_problem($newPw),
                    $newRole !== $target['role'] && $target['role'] === 'admin'
                        && (int) q("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1")->fetchColumn() <= 1
                        => 'Son aktif adminin rolü düşürülemez.',
                    default => null,
                };
                if ($problem !== null) {
                    // Modal yazılanlarla (şifre HARİÇ) ve hata mesajıyla yeniden açılır
                    flash('error', $problem);
                    $_SESSION['old_user'] = ['id' => $targetId, 'full_name' => (string) ($_POST['full_name'] ?? ''),
                                             'role' => $newRole, 'region' => $newRegion];
                    redirect('admin/users.php?edit=' . $targetId);
                }
                q('UPDATE users SET full_name = ?, role = ?, region = ? WHERE id = ?', [$newName, $newRole, $newRegion, $targetId]);
                $changes = array_filter([
                    'ad' => $newName !== $target['full_name'] ? $newName : null,
                    'rol' => $newRole !== $target['role'] ? ROLES[$newRole] : null,
                    'bölge' => $newRegion !== $target['region'] ? ($newRegion ?? '(tümü)') : null,
                ]);
                if ($changes) {
                    audit('user_update', $target['username'], $changes);
                }
                if ($newPw !== '') {
                    q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($newPw, PASSWORD_DEFAULT), $targetId]);
                    revoke_user_sessions($targetId); // eski şifreyle açık kalan cihazlar düşer
                    audit('user_password', $target['username']);
                }
                flash('success', 'Kullanıcı güncellendi' . ($newPw !== '' ? ', şifre değişti; açık cihazlarından çıkarıldı.' : '.'));
            }
        }
        redirect('admin/users.php');
    }
}

// Listeleme
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

$where = '1=1';
$params = [];
$qStr = trim($_GET['q'] ?? '');
if ($qStr !== '') {
    $where .= ' AND (username LIKE ? OR full_name LIKE ?)';
    $params[] = '%' . like_escape($qStr) . '%';
    $params[] = '%' . like_escape($qStr) . '%';
}

$total = (int) q("SELECT COUNT(*) FROM users WHERE $where", $params)->fetchColumn();
$usersList = q("SELECT u.*, (SELECT COUNT(*) FROM remember_tokens t WHERE t.user_id = u.id AND t.expires_at > UTC_TIMESTAMP()) AS devices
                FROM users u WHERE $where ORDER BY u.id DESC LIMIT ? OFFSET ?", [...$params, $perPage, $offset])->fetchAll();
$totalPages = max(1, (int) ceil($total / $perPage));

$regions = q("SELECT DISTINCT region FROM users WHERE region IS NOT NULL AND region <> '' ORDER BY region")->fetchAll(PDO::FETCH_COLUMN);
$regions = array_merge($regions, q("SELECT DISTINCT region FROM people WHERE region IS NOT NULL AND region <> '' ORDER BY region")->fetchAll(PDO::FETCH_COLUMN));
$regions = array_unique($regions);

$editId = (int) ($_GET['edit'] ?? 0);
$editUser = $editId ? (q('SELECT * FROM users WHERE id = ?', [$editId])->fetch() ?: null) : null;
$old = $_SESSION['old_user'] ?? [];
unset($_SESSION['old_user']);
$showModal = $editUser !== null || isset($_GET['new']);
$form = $editUser ?? $old; // düzenleme: kayıt; hata sonrası yeni kullanıcı: yazılanlar
if ($editUser && (int) ($old['id'] ?? 0) === (int) $editUser['id']) {
    // düzenleme hatası: kayıt yerine yazılanlar (şifre hariç)
    $form = array_merge($editUser, array_intersect_key($old, ['full_name' => 1, 'role' => 1, 'region' => 1]));
}

render_header('Kullanıcılar', 'users', ['admin.css']);
?>
<main class="container">
<div class="stack">
<dialog class="modal" id="user-modal" aria-labelledby="user-modal-title">
    <form method="post" class="stack modal-body">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="<?= $editUser ? 'edit' : 'create' ?>">
        <?php if ($editUser): ?>
            <input type="hidden" name="id" value="<?= e($editUser['id']) ?>">
        <?php endif; ?>
        <div class="modal-head">
            <h2 id="user-modal-title"><?= $editUser ? 'Düzenle: ' . e($editUser['username']) : 'Yeni kullanıcı' ?></h2>
            <a class="btn btn-ghost btn-sm modal-x" href="<?= e(url('admin/users.php')) ?>" aria-label="Kapat">✕</a>
        </div>
        <div class="field">
            <span>Kullanıcı adı</span>
            <input class="input" type="text" name="username" value="<?= e($form['username'] ?? '') ?>" <?= $editUser ? 'readonly' : 'required autofocus' ?> pattern="[a-zA-Z0-9._-]{3,64}" title="Harf, rakam, nokta, alt çizgi, tire; 3-64 karakter" autocapitalize="none" autocomplete="off">
            <?php if (!$editUser): ?><small>Benzersiz olmalı. Sadece harf, rakam, . _ - izin verilir.</small><?php endif; ?>
        </div>
        <div class="field">
            <span>Ad soyad</span>
            <input class="input" type="text" name="full_name" value="<?= e($form['full_name'] ?? '') ?>" required maxlength="120">
        </div>
        <div class="modal-grid">
            <div class="field">
                <span>Rol</span>
                <select class="input" name="role" required>
                    <?php foreach (ROLES as $rKey => $rLabel): ?>
                        <option value="<?= e($rKey) ?>" <?= ($form['role'] ?? 'onayci') === $rKey ? 'selected' : '' ?>><?= e($rLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <span>Bölge (opsiyonel)</span>
                <input class="input" type="text" name="region" value="<?= e($form['region'] ?? '') ?>" list="regions" maxlength="120">
                <datalist id="regions">
                    <?php foreach ($regions as $reg): ?>
                        <option value="<?= e($reg) ?>">
                    <?php endforeach; ?>
                </datalist>
            </div>
        </div>
        <small class="muted" style="margin-top:-6px">Onay ekibine bölge verilirse sadece o bölgeyi (ve bölgesiz kayıtları) görür. Boş = tüm bölgeler.</small>
        <div class="field">
            <span><?= $editUser ? 'Yeni şifre' : 'Şifre' ?></span>
            <input class="input" type="password" name="new_password" minlength="8" maxlength="72" autocomplete="new-password"<?= $editUser ? '' : ' required' ?>>
            <small><?= $editUser ? 'Boş bırakırsanız şifre değişmez. ' : '' ?>En az 8 karakter. Kullanıcıya siz iletin; şifre hiçbir yerde gösterilmez ya da kaydedilmez.</small>
        </div>
        <div class="row modal-actions">
            <a class="btn btn-ghost" href="<?= e(url('admin/users.php')) ?>">Vazgeç</a>
            <button type="submit" class="btn btn-primary"><?= $editUser ? 'Kaydet' : 'Oluştur' ?></button>
        </div>
    </form>
</dialog>
<?php if ($showModal): ?>
<script>
(function () {
    var d = document.getElementById('user-modal');
    if (!d) return;
    // Hata/bilgi mesajı karartılmış zeminin altında kalmasın: modalın içine taşı
    var head = d.querySelector('.modal-head');
    document.querySelectorAll('.flash').forEach(function (f) { head.after(f); f.style.margin = '0'; f.style.width = 'auto'; });
    if (!d.open && d.showModal) d.showModal();
    // Esc ile kapatınca listeye dön (sayfa durumu ile modal tutarlı kalsın)
    d.addEventListener('cancel', function (e) {
        e.preventDefault();
        d.querySelector('.modal-x').click();
    });
})();
</script>
<?php endif; ?>

<section class="card">
        <div class="row" style="justify-content: space-between; margin-bottom: 8px;">
            <h2 style="margin:0">Kullanıcılar <span class="muted" style="font-size:.9rem;font-weight:500">(<?= e($total) ?>)</span></h2>
            <a class="btn btn-primary" href="<?= e(url('admin/users.php?new=1')) ?>">+ Yeni kullanıcı</a>
        </div>
        <form method="get" class="row" style="margin-bottom: 12px;">
            <input class="input" type="text" name="q" value="<?= e($qStr) ?>" placeholder="Kullanıcı adı / ad soyad ara..." style="max-width: 300px;">
            <button type="submit" class="btn">Ara</button>
            <?php if ($qStr): ?>
                <a class="btn btn-ghost" href="<?= e(url('admin/users.php')) ?>">Temizle</a>
            <?php endif; ?>
        </form>
        <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Kullanıcı Adı</th>
                    <th>Ad Soyad</th>
                    <th>Rol</th>
                    <th>Bölge</th>
                    <th>Durum</th>
                    <th>Son Giriş</th>
                    <th title="Bu cihazda açık kalsın diyerek giriş yapılan cihazlar">Kayıtlı cihaz</th>
                    <th>İşlemler</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($usersList as $u): ?>
                <tr>
                    <td><?= e($u['username']) ?></td>
                    <td><?= e($u['full_name']) ?></td>
                    <td><span class="badge badge-role"><?= e(ROLES[$u['role']]) ?></span></td>
                    <td><?= e($u['region'] ?? '—') ?></td>
                    <td>
                        <span class="badge badge-<?= $u['is_active'] ? 'success' : 'danger' ?>">
                            <?= $u['is_active'] ? 'Aktif' : 'Pasif' ?>
                        </span>
                    </td>
                    <td><?= $u['last_login_at'] ? e(fmt_datetime($u['last_login_at'])) : '—' ?></td>
                    <td class="num"><?= e((int) $u['devices']) ?></td>
                    <td>
                        <div class="row" style="gap: 4px;">
                            <?php if (true): /* düzenleme artık modalda */ ?>
                                <a class="btn btn-sm" href="<?= e(url('admin/users.php?edit=' . $u['id'])) ?>">Düzenle / şifre</a>
                                <form method="post" class="inline" onsubmit="return confirm('Durum değiştirilsin mi?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle_active">
                                    <input type="hidden" name="id" value="<?= e($u['id']) ?>">
                                    <button type="submit" class="btn btn-sm <?= $u['is_active'] ? 'btn-ghost' : '' ?>"><?= $u['is_active'] ? 'Pasife Al' : 'Aktif Et' ?></button>
                                </form>
                                <?php if ($u['is_active']): ?>
                                <form method="post" class="inline" onsubmit="return confirm('<?= e($u['username']) ?> tüm cihazlardan çıkarılsın mı? Tekrar şifreyle girmesi gerekecek.');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="revoke_sessions">
                                    <input type="hidden" name="id" value="<?= e($u['id']) ?>">
                                    <button type="submit" class="btn btn-sm btn-ghost">Cihazlardan çıkar</button>
                                </form>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="muted">Düzenleniyor…</span>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$usersList): ?>
                <tr><td colspan="8" class="empty">Kullanıcı yok.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>

        <?php if ($totalPages > 1): ?>
        <nav class="row" style="justify-content: center; margin-top: 12px;">
            <?php
            $query = $_GET;
            for ($p = 1; $p <= $totalPages; $p++) {
                $query['page'] = $p;
                $href = url('admin/users.php?' . http_build_query($query));
                $cls = $p === $page ? 'btn' : 'btn btn-ghost';
                echo '<a class="' . $cls . '" href="' . e($href) . '">' . $p . '</a>';
            }
            ?>
        </nav>
        <?php endif; ?>
    </section>
</div>
</main>
<?php
render_footer();