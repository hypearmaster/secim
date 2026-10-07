<?php
declare(strict_types=1);

// Tek komutla kurulum (tekrar çalıştırmak güvenli; var olanı silmez):
//   C:\xampp\php\php.exe bin\setup.php                -> config + veritabanı + tablolar + admin
//   C:\xampp\php\php.exe bin\setup.php --demo         -> + 240 kişilik demo liste ve test hesapları
// Admin zaten varsa yenisi açılmaz. Şifreler ekrana BİR KEZ yazılır.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$demo = in_array('--demo', $argv, true);

function step(string $msg): void { echo "\n> $msg\n"; }
function fail(string $msg): never { fwrite(STDERR, "\nHATA: $msg\n"); exit(1); }

// 1) vendor
if (!is_file("$root/vendor/autoload.php")) {
    fail("vendor/ yok. Önce proje klasöründe 'composer install' çalıştırın.");
}

// 2) config.php
step('config.php');
if (!is_file("$root/config.php")) {
    $cfg = file_get_contents("$root/config.example.php");
    $cfg = str_replace("'debug'    => false", "'debug'    => true", $cfg); // yerel kurulum: hataları göster
    file_put_contents("$root/config.php", $cfg);
    echo "  oluşturuldu (config.example.php kopyası, debug açık)\n";
} else {
    echo "  zaten var, dokunulmadı\n";
}
$config = require "$root/config.php";
$c = $config['db'];

// 3) veritabanı
step("veritabanı '{$c['name']}'");
try {
    $server = new PDO(
        sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $c['host'], (int) $c['port']),
        $c['user'], $c['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    fail("MySQL'e bağlanılamadı: {$e->getMessage()}\n  XAMPP Control Panel'den MySQL'i başlatın ve config.php'deki db bilgilerini kontrol edin.");
}
if (!preg_match('/^[A-Za-z0-9_]+$/', $c['name'])) {
    fail('config.php db.name sadece harf, rakam ve _ içermeli.');
}
$server->exec("CREATE DATABASE IF NOT EXISTS `{$c['name']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_turkish_ci");
echo "  hazır\n";

// 4) tablolar (schema.sql: CREATE TABLE IF NOT EXISTS — var olan veriye dokunmaz)
step('tablolar');
$server->exec("USE `{$c['name']}`");
$sql = file_get_contents("$root/sql/schema.sql");
$sql = preg_replace('/^\s*--.*$/m', '', $sql);
// Sadece satır sonundaki ';' ile böl: COMMENT metinlerinin içinde de ';' geçiyor.
foreach (array_filter(array_map('trim', preg_split('/;\s*$/m', $sql))) as $statement) {
    $server->exec($statement);
}
$tables = $server->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
echo '  ' . implode(', ', $tables) . "\n";

// Buradan sonrası normal uygulama fonksiyonlarıyla
require "$root/src/bootstrap.php";

// 4b) göçler: CREATE TABLE IF NOT EXISTS eski tabloya yeni sütun eklemez
step('güncellemeler');
$has = fn(string $table, string $col) => (bool) q(
    'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
    [$table, $col]
)->fetchColumn();
$migrated = 0;
if (!$has('people', 'firm_key')) {
    db()->exec("ALTER TABLE people ADD COLUMN firm_key VARCHAR(255) NOT NULL DEFAULT '' AFTER search_key, ADD KEY ix_people_event_firm (event_id, firm_key)");
    $migrated++;
}
if (!$has('people', 'phone')) {
    db()->exec("ALTER TABLE people ADD COLUMN phone VARCHAR(20) NULL AFTER ref_no");
    echo "  kişiler: telefon sütunu eklendi\n";
}
if (!$has('users', 'auth_version')) {
    db()->exec("ALTER TABLE users ADD COLUMN auth_version INT UNSIGNED NOT NULL DEFAULT 0 AFTER last_login_at");
    echo "  kullanıcılar: oturum sürümü sütunu eklendi\n";
}
if (!$has('supporters', 'source')) {
    db()->exec("ALTER TABLE supporters ADD COLUMN source ENUM('list','manual') NOT NULL DEFAULT 'list' AFTER status");
    echo "  Bizimkiler: kaynak sütunu eklendi\n";
}
$empty = q("SELECT id, full_name, authority, ref_no FROM people WHERE firm_key = ''")->fetchAll();
foreach ($empty as $p) {
    q('UPDATE people SET firm_key = ? WHERE id = ?', [firm_key($p['full_name'], $p['authority'], $p['ref_no']), $p['id']]);
}
echo $migrated || $empty ? '  firma gruplama: ' . count($empty) . " kayıt güncellendi\n" : "  güncel\n";

function make_user(string $username, string $role, string $fullName, ?string $region = null): void
{
    if (q('SELECT 1 FROM users WHERE username = ?', [$username])->fetchColumn()) {
        echo "  $username zaten var, atlandı\n";
        return;
    }
    $pw = generate_password(12);
    q('INSERT INTO users (username, password_hash, full_name, role, region, created_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())',
        [$username, password_hash($pw, PASSWORD_DEFAULT), $fullName, $role, $region]);
    printf("  %-10s %-11s şifre: %s%s\n", $username, ROLES[$role], $pw, $region ? "  (bölge: $region)" : '');
}

// 5) admin
step('admin hesabı');
$hasAdmin = (int) q("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1")->fetchColumn();
if ($hasAdmin) {
    echo "  aktif admin zaten var, yenisi açılmadı\n";
    echo "  (şifreyi unuttuysanız: C:\\xampp\\php\\php.exe bin\\create-user.php admin admin \"Ad Soyad\")\n";
} else {
    make_user('admin', 'admin', 'Yönetici');
}

// 6) demo
if ($demo) {
    step('demo veri');
    if ((int) q('SELECT COUNT(*) FROM events')->fetchColumn() > 0) {
        echo "  zaten etkinlik var, demo liste eklenmedi\n";
    } else {
        passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$root/bin/seed-demo.php"));
    }
    make_user('onay1', 'onayci', 'Test Onaycı', 'Merkez');
    make_user('yonetici1', 'yonetici', 'Test Yönetici');
}

echo "\nKurulum tamam. Şifreleri not alın, tekrar gösterilmez.\n";
echo 'Tarayıcıda açın: http://localhost' . rtrim((string) config('base_url'), '/') . "/\n";
