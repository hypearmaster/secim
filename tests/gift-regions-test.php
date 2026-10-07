<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
require __DIR__ . '/../src/bootstrap.php';
require APP_ROOT . '/src/gifts.php';

function verify_region(string $label, bool $ok): void
{
    if (!$ok) throw new RuntimeException($label);
    echo "OK: $label\n";
}

$event = active_event();
if (!$event) throw new RuntimeException('Aktif etkinlik gerekli.');
$user = q("SELECT id, role FROM users WHERE role = 'admin' AND is_active = 1 LIMIT 1")->fetch();
$user['id'] = (int) $user['id'];
$tag = 'region-test-' . bin2hex(random_bytes(5));
$pdo = db();
$pdo->beginTransaction();
try {
    $create = function (int $eventId, string $name, ?string $region, ?string $authority = null) use ($tag): int {
        q('INSERT INTO people (event_id, full_name, authority, region, search_key, firm_key) VALUES (?, ?, ?, ?, ?, ?)',
            [$eventId, $name, $authority, $region, search_key($name), $tag . $name . (string) $region]);
        return (int) db()->lastInsertId();
    };
    $create($event['id'], $tag . '-firma', 'Test Bölge A', $tag . '-yetkili');
    $create($event['id'], $tag . '-ortak', 'Test Bölge A');
    $create($event['id'], $tag . '-ortak', 'Test Bölge B');
    q('INSERT INTO events (name, is_active, created_by) VALUES (?, 0, ?)', [$tag, $user['id']]);
    $other = (int) $pdo->lastInsertId();
    $create($other, $tag . '-yetkili', 'Başka Etkinlik');
    $pid = $create($event['id'], $tag . '-bagli', 'Test Bölge B');
    q("INSERT INTO supporters (event_id, name, search_key, status, created_by) VALUES (?, ?, ?, 'matched', ?)",
        [$event['id'], $tag . '-takma', search_key($tag . '-takma'), $user['id']]);
    q('INSERT INTO supporter_people (supporter_id, person_id) VALUES (?, ?)', [(int) $pdo->lastInsertId(), $pid]);
    $index = gift_region_index($event['id']);
    verify_region('yetkili eşleşir, başka etkinlik karışmaz', gift_matched_region($tag . '-yetkili', $index) === 'Test Bölge A');
    verify_region('farklı bölgelerde aynı isim belirsiz', gift_matched_region($tag . '-ortak', $index) === null);
    verify_region('bulunamayan isim belirsiz', gift_matched_region($tag . '-yok', $index) === null);
    verify_region('onaylı Bizimkiler bağlantısından bölge alınır', gift_matched_region($tag . '-takma', $index) === 'Test Bölge B');
    $g = add_gift($user, $tag . '-yetkili')['gift'];
    verify_region('yeni hediye bölgesi otomatik kaydedilir', $g['region'] === 'Test Bölge A');
    q('UPDATE gifts SET region = NULL, given_at = UTC_TIMESTAMP(), given_by = ? WHERE id = ?', [$user['id'], $g['id']]);
    sync_gift_regions($event['id']);
    $saved = q('SELECT region, given_at, given_by FROM gifts WHERE id = ?', [$g['id']])->fetch();
    verify_region('mevcut hediyenin bölgesi doldurulur, verildi korunur', $saved['region'] === 'Test Bölge A' && $saved['given_at'] !== null && (int) $saved['given_by'] === $user['id']);
    $manual = set_gift_region($user, $g['id'], 'Test Bölge B', $event['id']);
    verify_region('elle bölge kaydı verildi bilgisini korur', $manual['ok'] && $manual['gift']['given'] && $manual['gift']['region'] === 'Test Bölge B');
    sync_gift_regions($event['id']);
    verify_region('elle seçilen bölge otomatik eşleşmeyle ezilmez', q('SELECT region FROM gifts WHERE id = ?', [$g['id']])->fetchColumn() === 'Test Bölge B');
    $filtered = search_gifts(['q' => $tag, 'region' => 'Test Bölge B']);
    verify_region('bölge filtresi çalışır', in_array($g['id'], array_column($filtered['items'], 'id'), true));
    verify_region('diğer bölgede görünmez', !in_array($g['id'], array_column(search_gifts(['q' => $tag, 'region' => 'Test Bölge A'])['items'], 'id'), true));
    $unknown = add_gift($user, $tag . '-yok')['gift'];
    verify_region('belirsiz filtresi çalışır', in_array($unknown['id'], array_column(search_gifts(['q' => $tag, 'region' => NONE_VALUE])['items'], 'id'), true));
    verify_region('listede olmayan bölge reddedilir', set_gift_region($user, $g['id'], $tag . '-gecersiz', $event['id'])['status'] === 422);
    verify_region('yanlış etkinlik reddedilir', set_gift_region($user, $g['id'], 'Test Bölge A', $other)['status'] === 409);
    verify_region('yetkisiz bölge değişimi reddedilir', set_gift_region(['id' => $user['id'], 'role' => 'invalid'], $g['id'], 'Test Bölge A', $event['id'])['status'] === 403);
} finally {
    $pdo->rollBack(); // Test kayıtları dahil bütün değişiklikler geri alınır.
}
