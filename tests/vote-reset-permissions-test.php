<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
require __DIR__ . '/../src/bootstrap.php';
require APP_ROOT . '/src/votes.php';
foreach (['admin' => true, 'yonetici' => true, 'onayci' => false] as $role => $expected) {
    if (can('vote_reset', ['role' => $role]) !== $expected) throw new RuntimeException($role);
}
$result = vote_reset(['role' => 'onayci']);
if ($result['status'] !== 403) throw new RuntimeException('Onaycı sunucuda engellenmedi.');
echo "OK: admin/yönetici yetkili, onaycı sunucuda reddedilir\n";
