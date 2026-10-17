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
    'export'        => ['admin'],                       // dışarı veri çıkışı sadece admin
    'view_log'      => ['admin', 'yonetici'],           // GELDİ işlem kayıtları
    'view_audit'    => ['admin'],                       // denetim kaydı (giriş, kullanıcı, liste, export)
    'supporters'    => ['admin', 'yonetici'],           // Bizimkiler listesini görür
    'manage_supporters' => ['admin'],                   // Bizimkiler yükler / eşleştirir
    'stance'        => ['admin', 'yonetici'],           // "Bizimle mi?" sütununu görür ve değiştirir
    'transfer'      => ['admin', 'yonetici'],           // "Transfer" çipini görür ve değiştirir (stance ile aynı hassasiyet)
    'call'          => ['admin', 'yonetici', 'onayci'], // kartta telefon simgesi (numara sadece buna gönderilir)
    'vote'          => ['admin', 'yonetici'],           // oy sayacı: görür ve sayar
    'vote_reset'    => ['admin', 'yonetici'],             // oy sayacını sıfırlar
    'gift_mark'     => ['admin', 'yonetici', 'onayci'], // hediye verdik/vermedik işaretler
    'gift_manage'   => ['admin'],                        // hediye listesi yükler/siler
    'add_person'    => ['admin', 'yonetici'],           // listeye elle yeni kişi ekler
    'team'          => ['admin', 'yonetici', 'onayci'], // kişiyi kendi takımına alır/çıkarır + "Kimin adamı" filtresi
    'team_manage'   => ['admin', 'yonetici'],           // başkasının takımından çıkarır
    'import'        => ['admin'],
    'manage_events' => ['admin'],
    'manage_users'  => ['admin'],
];

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
}

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $https = is_https();
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
    // Önbellek oturumdaki uid'ye bağlı: aynı istekte giriş yapılınca eski "kimse yok" sonucu dönmesin.
    static $cachedUid = null, $user = null, $triedRemember = false;
    $uid = $_SESSION['uid'] ?? null;
    if (!is_int($uid) && !$triedRemember && PHP_SAPI !== 'cli') {
        // Oturum yok (tarayıcı kapanıp açıldı): "bu cihazda açık kalsın" çerezi varsa sessizce giriş
        $triedRemember = true;
        $uid = remember_login();
    }
    if (!is_int($uid)) {
        return null;
    }
    if ($cachedUid === $uid) {
        return $user;
    }
    $cachedUid = $uid;
    $user = null;
    $row = q('SELECT id, username, full_name, role, region, is_active, auth_version FROM users WHERE id = ?', [$uid])->fetch();
    // Bu özellikten önce açılmış oturumda 'av' yok: 0 sayılır (göçte herkes 0). Kullanıcı bir kez
    // "cihazlardan çıkarıldı" ya da şifresi değiştiyse sürüm > 0 olur ve o eski oturumlar da düşer.
    $av = $_SESSION['av'] ?? 0;
    if (!$row || !(int) $row['is_active'] || !isset(ROLES[$row['role']])
        || !is_int($av) || $av !== (int) $row['auth_version']) { // şifre değişti / cihazlardan çıkarıldı
        logout();
        return null;
    }
    unset($row['auth_version']);
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
function attempt_login(string $username, string $password, bool $remember = false): string
{
    $username = mb_substr(trim($username), 0, 64);
    $ip = client_ip_bin();

    // Önce denemeyi YAZ, sonra say: paralel istekler "say → yaz" arasındaki boşluktan
    // limiti aşamaz (her istek kendi satırını görür). Başarılı girişte satırlar silinir.
    q('INSERT INTO login_attempts (username, ip, created_at) VALUES (?, ?, UTC_TIMESTAMP())', [$username, $ip]);
    $window = 'created_at > UTC_TIMESTAMP() - INTERVAL 15 MINUTE';
    $byUser = (int) q("SELECT COUNT(*) FROM login_attempts WHERE username = ? AND $window", [$username])->fetchColumn();
    $byIp = $ip === null ? 0 : (int) q("SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND $window", [$ip])->fetchColumn();
    if ($byUser > (int) config('login_max_per_user', 5) || $byIp > (int) config('login_max_per_ip', 30)) {
        audit('login_locked', $username);
        return 'locked'; // şifre hiç denenmez
    }

    $row = q('SELECT id, password_hash, is_active FROM users WHERE username = ?', [$username])->fetch();
    // Kullanıcı yoksa da hash doğrula: yanıt süresinden kullanıcı adı tahmin edilmesin.
    $hash = $row['password_hash'] ?? '$2y$10$SLGt8aKuL8.DHemOxT0aNufjV5WwPXzkuYvdrA81tviuD3l13ZKcu';
    $valid = password_verify($password, $hash) && $row && (int) $row['is_active'] === 1;

    if (!$valid) {
        audit('login_failed', $username, $row && !(int) $row['is_active'] ? ['sebep' => 'pasif kullanıcı'] : []);
        return 'invalid';
    }

    q('DELETE FROM login_attempts WHERE username = ?', [$username]);
    if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
        q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $row['id']]);
    }
    q('UPDATE users SET last_login_at = UTC_TIMESTAMP() WHERE id = ?', [$row['id']]);

    begin_user_session((int) $row['id']);
    if ($remember) {
        remember_issue((int) $row['id']);
    }
    audit('login', $username, $remember ? ['cihazda açık kalsın' => 'evet'] : [], (int) $row['id']);
    return 'ok';
}

/** Yeni oturum kimliği + kullanıcı (oturum sabitleme saldırısına karşı her girişte yeni kimlik). */
function begin_user_session(int $uid, ?string $rememberSelector = null): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
    $av = (int) q('SELECT auth_version FROM users WHERE id = ?', [$uid])->fetchColumn();
    $_SESSION = ['uid' => $uid, 'av' => $av];
    if ($rememberSelector !== null) {
        $_SESSION['rm'] = $rememberSelector;
    }
    csrf_token();
}

function logout(): void
{
    remember_forget_current();
    $_SESSION = [];
    if (ini_get('session.use_cookies') && PHP_SAPI !== 'cli' && !headers_sent()) {
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

/** Admin'in belirlediği şifre kuralı. Hata mesajı ya da null döner. */
function password_problem(string $pw): ?string
{
    if (mb_strlen($pw) < 8) {
        return 'Şifre en az 8 karakter olmalı.';
    }
    if (mb_strlen($pw) > 72) {
        return 'Şifre en fazla 72 karakter olabilir.';
    }
    return null;
}

/** Okunaklı rastgele şifre (karışan 0/O, 1/l/I yok). Sadece komut satırı kurulumunda. */
function generate_password(int $len = 10): string
{
    $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $out = '';
    for ($i = 0; $i < $len; $i++) {
        $out .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $out;
}

/* ---------- "Bu cihazda oturumum açık kalsın" ---------- */

/*
 * Telefon tarayıcısı kapanınca oturum çerezi gider; bu çerez 'remember_days' gün kalır.
 * Güvenlik:
 *  - Çerez "selector:validator". DB'de validator'ın sadece SHA-256'sı var (DB sızsa da çerez üretilemez).
 *  - Her kullanımda validator yenilenir. Eski validator 2 dk geçerli (aynı anda giden istekler için),
 *    sonra gelirse çerez kopyalanmış sayılır: o cihaz kaydı silinir ve denetime yazılır.
 *  - Çıkış o cihazın kaydını siler. Şifre değişimi / pasife alma / "Tüm cihazlardan çıkar"
 *    hepsini siler ve açık oturumları da düşürür (users.auth_version).
 *  - HttpOnly (JS okuyamaz), HTTPS'te Secure, SameSite=Lax. Kullanıcı başına en fazla 10 cihaz.
 */
const REMEMBER_COOKIE = 'secim_rm';
const REMEMBER_GRACE_SECONDS = 120;
const REMEMBER_MAX_DEVICES = 10;

function remember_set_cookie(string $value, int $expires): void
{
    if (PHP_SAPI === 'cli' || headers_sent()) {
        return;
    }
    setcookie(REMEMBER_COOKIE, $value, [
        'expires'  => $expires,
        'path'     => rtrim((string) config('base_url', ''), '/') . '/',
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/** Bu cihaz için yeni hatırlama kaydı açar ve çerezi yazar. */
function remember_issue(int $uid): void
{
    $days = max(1, (int) config('remember_days', 30));
    $selector = bin2hex(random_bytes(12));
    $validator = bin2hex(random_bytes(32));
    q('DELETE FROM remember_tokens WHERE expires_at <= UTC_TIMESTAMP()');
    q('INSERT INTO remember_tokens (user_id, selector, validator_hash, user_agent, ip, created_at, last_used_at, expires_at)
       VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP() + INTERVAL ? DAY)',
        [$uid, $selector, hash('sha256', $validator), mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 190), client_ip_bin(), $days]);
    q('DELETE FROM remember_tokens WHERE user_id = ? AND id NOT IN (
         SELECT id FROM (SELECT id FROM remember_tokens WHERE user_id = ? ORDER BY id DESC LIMIT ' . REMEMBER_MAX_DEVICES . ') keep)', [$uid, $uid]);
    $_SESSION['rm'] = $selector;
    remember_set_cookie("$selector:$validator", time() + $days * 86400);
}

/**
 * Çerezi doğrular ve validator'ı yeniler (çerez/oturum işi yapmaz; test edilebilir çekirdek).
 * @return ?array{uid:int, username:string, selector:string, cookie:?string, expires:int}
 *         cookie = tarayıcıya yazılacak yeni değer (null: yazma, eşzamanlı istek yeniledi)
 */
function remember_consume(string $raw): ?array
{
    if (!preg_match('/^([a-f0-9]{24}):([a-f0-9]{64})$/', $raw, $m)) {
        return null;
    }
    [, $selector, $validator] = $m;
    $row = q('SELECT t.id, t.user_id, t.validator_hash, t.prev_hash, UNIX_TIMESTAMP(t.expires_at) AS exp,
                     (t.rotated_at > UTC_TIMESTAMP() - INTERVAL ' . REMEMBER_GRACE_SECONDS . ' SECOND) AS in_grace,
                     u.username, u.is_active
              FROM remember_tokens t JOIN users u ON u.id = t.user_id
              WHERE t.selector = ? AND t.expires_at > UTC_TIMESTAMP()', [$selector])->fetch();
    if (!$row) {
        return null;
    }
    $out = ['uid' => (int) $row['user_id'], 'username' => $row['username'], 'selector' => $selector, 'cookie' => null, 'expires' => (int) $row['exp']];
    if (!(int) $row['is_active']) {
        q('DELETE FROM remember_tokens WHERE id = ?', [$row['id']]);
        return null;
    }
    $hash = hash('sha256', $validator);
    if (hash_equals($row['validator_hash'], $hash)) {
        $new = bin2hex(random_bytes(32));
        // Koşullu: aynı anda iki istek yenilemeye çalışırsa sadece biri yazar
        $st = q('UPDATE remember_tokens SET prev_hash = validator_hash, validator_hash = ?, rotated_at = UTC_TIMESTAMP(),
                        last_used_at = UTC_TIMESTAMP(), ip = ?, user_agent = ?
                 WHERE id = ? AND validator_hash = ?',
            [hash('sha256', $new), client_ip_bin(), mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 190), $row['id'], $hash]);
        if ($st->rowCount() === 1) {
            $out['cookie'] = "$selector:$new";
        }
        return $out;
    }
    if ($row['prev_hash'] !== null && (int) $row['in_grace'] === 1 && hash_equals($row['prev_hash'], $hash)) {
        return $out; // az önce yenilendi; yeni çerez diğer yanıtla gitti
    }
    // Eski/yanlış validator: çerez kopyalanmış olabilir. Bu cihaz kaydı iptal (yeniden şifre ister).
    q('DELETE FROM remember_tokens WHERE id = ?', [$row['id']]);
    audit('remember_reuse', $row['username'], ['sebep' => 'eski ya da yanlış cihaz anahtarı; cihaz kaydı iptal edildi'], (int) $row['user_id']);
    return null;
}

/** Oturum yokken çağrılır: geçerli çerez varsa oturumu açar. @return kullanıcı id ya da null */
function remember_login(): ?int
{
    $raw = $_COOKIE[REMEMBER_COOKIE] ?? null;
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    try {
        $r = remember_consume($raw);
    } catch (Throwable $e) {
        error_log('remember_login: ' . $e->getMessage());
        return null;
    }
    if ($r === null) {
        remember_set_cookie('', time() - 3600);
        return null;
    }
    if ($r['cookie'] !== null) {
        remember_set_cookie($r['cookie'], $r['expires']);
    }
    begin_user_session($r['uid'], $r['selector']);
    q('UPDATE users SET last_login_at = UTC_TIMESTAMP() WHERE id = ?', [$r['uid']]);
    audit('login_auto', $r['username'], [], $r['uid']);
    return $r['uid'];
}

/** Bu cihazın hatırlama kaydını siler, çerezi temizler (çıkış). */
function remember_forget_current(): void
{
    $selector = $_SESSION['rm'] ?? null;
    $raw = $_COOKIE[REMEMBER_COOKIE] ?? null;
    if (!is_string($selector) && is_string($raw) && preg_match('/^([a-f0-9]{24}):/', $raw, $m)) {
        $selector = $m[1];
    }
    if (is_string($selector)) {
        try {
            q('DELETE FROM remember_tokens WHERE selector = ?', [$selector]);
        } catch (Throwable $e) {
            error_log('remember_forget: ' . $e->getMessage());
        }
    }
    if ($raw !== null) {
        remember_set_cookie('', time() - 3600);
    }
}

/**
 * Kullanıcının TÜM cihazlarını ve açık oturumlarını düşürür (şifre değişti, pasif, "cihazlardan çıkar").
 * İşlemi yapan kendisiyse bu oturumu korunur (kendini dışarı atmasın).
 */
function revoke_user_sessions(int $uid): void
{
    q('UPDATE users SET auth_version = auth_version + 1 WHERE id = ?', [$uid]);
    q('DELETE FROM remember_tokens WHERE user_id = ?', [$uid]);
    if (($_SESSION['uid'] ?? null) === $uid) {
        $_SESSION['av'] = (int) q('SELECT auth_version FROM users WHERE id = ?', [$uid])->fetchColumn();
        if (isset($_SESSION['rm'])) {
            remember_issue($uid); // bu cihaz açık kalsın
        }
    }
}

/** @return int kullanıcının "açık kalsın" dediği geçerli cihaz sayısı */
function remember_device_count(int $uid): int
{
    return (int) q('SELECT COUNT(*) FROM remember_tokens WHERE user_id = ? AND expires_at > UTC_TIMESTAMP()', [$uid])->fetchColumn();
}
