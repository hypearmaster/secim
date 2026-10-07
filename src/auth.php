<?php
declare(strict_types=1);

const ROLES = [
    'admin'    => 'Admin',
    'yonetici' => 'Yönetici',
    'onayci'   => 'Onay Ekibi',
];

/**
 * Yetki matrisi — TEK GERÇEK KAYNAK. Sayfalar rol adı değil yetki sorar: can('export').
 * UI'da buton gizlemek yetmez; her endpoint require_perm() ile sunucuda kontrol eder.
 */
const PERMISSIONS = [
    'mark'          => ['admin', 'yonetici', 'onayci'], // GELDİ işaretle
    'unmark_any'    => ['admin', 'yonetici'],           // herkesin işaretini geri al (onayci: sadece kendi + süre içinde)
    'view_all'      => ['admin', 'yonetici'],           // bölge kısıtı yok
    'dashboard'     => ['admin', 'yonetici'],
    'export'        => ['admin', 'yonetici'],
    'view_log'      => ['admin', 'yonetici'],
    'stance'        => ['admin', 'yonetici'],           // "Bizimle mi?" sütununu görür ve değiştirir
    'import'        => ['admin'],
    'manage_events' => ['admin'],
    'manage_users'  => ['admin'],
];

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', (string) (60 * 60 * 12));
    session_name('secim_sid');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/** Giriş yapmış kullanıcı (her istekte DB'den taze okunur; pasife alınan anında düşer). */
function current_user(): ?array
{
    static $loaded = false, $user = null;
    if ($loaded) {
        return $user;
    }
    $loaded = true;
    $uid = $_SESSION['uid'] ?? null;
    if (!is_int($uid)) {
        return null;
    }
    $row = q('SELECT id, username, full_name, role, region, is_active FROM users WHERE id = ?', [$uid])->fetch();
    if (!$row || !(int) $row['is_active'] || !isset(ROLES[$row['role']])) {
        logout();
        return null;
    }
    $row['id'] = (int) $row['id'];
    return $user = $row;
}

function can(string $perm, ?array $user = null): bool
{
    $user ??= current_user();
    return $user !== null && in_array($user['role'], PERMISSIONS[$perm] ?? [], true);
}

function require_login(): array
{
    $user = current_user();
    if ($user === null) {
        if (is_api_request()) {
            json_response(['ok' => false, 'error' => 'Oturum kapandı, tekrar giriş yapın.'], 401);
        }
        redirect('index.php');
    }
    return $user;
}

function require_perm(string $perm): array
{
    $user = require_login();
    if (!can($perm, $user)) {
        if (is_api_request()) {
            json_response(['ok' => false, 'error' => 'Bu işlem için yetkiniz yok.'], 403);
        }
        http_response_code(403);
        render_header('Yetki yok');
        echo '<main class="container"><div class="card"><h1>Yetkiniz yok</h1><p>Bu sayfayı görme yetkiniz bulunmuyor.</p>'
            . '<a class="btn" href="' . e(url('app.php')) . '">Onay ekranına dön</a></div></main>';
        render_footer();
        exit;
    }
    return $user;
}

/** Rol adına göre ilk açılacak sayfa. */
function home_path(?array $user = null): string
{
    return can('dashboard', $user) ? 'admin/index.php' : 'app.php';
}

/**
 * @return 'ok'|'invalid'|'locked'
 */
function attempt_login(string $username, string $password): string
{
    $username = mb_substr(trim($username), 0, 64);
    $ip = client_ip_bin();

    $window = 'created_at > UTC_TIMESTAMP() - INTERVAL 15 MINUTE';
    $byUser = (int) q("SELECT COUNT(*) FROM login_attempts WHERE username = ? AND $window", [$username])->fetchColumn();
    $byIp = $ip === null ? 0 : (int) q("SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND $window", [$ip])->fetchColumn();
    if ($byUser >= (int) config('login_max_per_user', 5) || $byIp >= (int) config('login_max_per_ip', 30)) {
        return 'locked';
    }

    $row = q('SELECT id, password_hash, is_active FROM users WHERE username = ?', [$username])->fetch();
    // Kullanıcı yoksa da hash doğrula: yanıt süresinden kullanıcı adı tahmin edilmesin.
    $hash = $row['password_hash'] ?? '$2y$10$SLGt8aKuL8.DHemOxT0aNufjV5WwPXzkuYvdrA81tviuD3l13ZKcu';
    $valid = password_verify($password, $hash) && $row && (int) $row['is_active'] === 1;

    if (!$valid) {
        q('INSERT INTO login_attempts (username, ip, created_at) VALUES (?, ?, UTC_TIMESTAMP())', [$username, $ip]);
        return 'invalid';
    }

    q('DELETE FROM login_attempts WHERE username = ?', [$username]);
    if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
        q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $row['id']]);
    }
    q('UPDATE users SET last_login_at = UTC_TIMESTAMP() WHERE id = ?', [$row['id']]);

    session_regenerate_id(true);
    $_SESSION = ['uid' => (int) $row['id']];
    csrf_token();
    return 'ok';
}

function logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

/** Kullanıcı adı kuralı: harf, rakam, nokta, alt çizgi, tire; 3-64. */
function valid_username(string $u): bool
{
    return (bool) preg_match('/^[a-zA-Z0-9._-]{3,64}$/', $u);
}

/** Okunaklı rastgele şifre (karışan 0/O, 1/l/I yok). */
function generate_password(int $len = 10): string
{
    $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $out = '';
    for ($i = 0; $i < $len; $i++) {
        $out .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $out;
}
