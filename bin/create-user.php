<?php
declare(strict_types=1);

// Komut satırından kullanıcı oluşturur / şifre sıfırlar. İlk admin böyle açılır.
//   php bin/create-user.php <kullanici_adi> <admin|yonetici|onayci> "<Ad Soyad>" [bolge]
// Şifre rastgele üretilip ekrana BİR KEZ yazılır. Kullanıcı varsa şifresi sıfırlanır.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../src/bootstrap.php';

[$self, $username, $role, $fullName] = array_pad($argv, 4, null);
$region = $argv[4] ?? null;

if (!$username || !$role || !$fullName || !isset(ROLES[$role]) || !valid_username($username)) {
    fwrite(STDERR, "Kullanım: php bin/create-user.php <kullanici_adi> <admin|yonetici|onayci> \"<Ad Soyad>\" [bolge]\n");
    exit(1);
}

$password = generate_password(12);
$hash = password_hash($password, PASSWORD_DEFAULT);
$exists = q('SELECT id FROM users WHERE username = ?', [$username])->fetchColumn();

if ($exists) {
    q('UPDATE users SET password_hash = ?, role = ?, full_name = ?, region = ?, is_active = 1 WHERE id = ?',
        [$hash, $role, $fullName, $region ?: null, $exists]);
    echo "Güncellendi: $username\n";
} else {
    q('INSERT INTO users (username, password_hash, full_name, role, region, created_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())',
        [$username, $hash, $fullName, $role, $region ?: null]);
    echo "Oluşturuldu: $username\n";
}
echo "Rol: " . ROLES[$role] . ($region ? " · Bölge: $region" : '') . "\n";
echo "Şifre: $password\n";
