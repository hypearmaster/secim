<?php
declare(strict_types=1);

// Geliştirme için örnek veri: "Demo Etkinlik" açar, 240 rastgele kişi ekler.
//   php bin/seed-demo.php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../src/bootstrap.php';

$admin = q("SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1")->fetchColumn();
if (!$admin) {
    fwrite(STDERR, "Önce bir admin oluşturun: php bin/create-user.php admin admin \"Ad Soyad\"\n");
    exit(1);
}

$first = ['Ahmet', 'Mehmet', 'Ayşe', 'Fatma', 'Şükrü', 'İsmail', 'Işıl', 'Çağrı', 'Gülşen', 'Ömer', 'Ümit', 'Zeynep', 'Hüseyin', 'Elif', 'Doğan', 'Özlem', 'Burak', 'Merve', 'İbrahim', 'Sevgi'];
$last = ['Yılmaz', 'Kaya', 'Demir', 'Şahin', 'Çelik', 'Yıldız', 'Öztürk', 'Aydın', 'Özdemir', 'Arslan', 'Doğan', 'Kılıç', 'Aslan', 'Çetin', 'Kara', 'Koç', 'Kurt', 'Özkan', 'Şimşek', 'Polat'];
$authorities = ['Başkan', 'Başkan Yardımcısı', 'Sekreter', 'Üye', 'Üye', 'Üye', 'Müşahit', 'Müşahit', 'Gönüllü'];
$regions = ['Merkez', 'Kuzey', 'Güney', 'Doğu', 'Batı'];
$groups = ['A Grubu', 'B Grubu', 'Gençlik', 'Kadın Kolları', null, null];

$eventId = create_event('Demo Etkinlik ' . date('d.m.Y'), (int) $admin);
mt_srand(42);
db()->beginTransaction();
for ($i = 1; $i <= 240; $i++) {
    insert_person($eventId, null, [
        'full_name'  => $first[mt_rand(0, count($first) - 1)] . ' ' . $last[mt_rand(0, count($last) - 1)],
        'authority'  => $authorities[mt_rand(0, count($authorities) - 1)],
        'region'     => $regions[mt_rand(0, count($regions) - 1)],
        'group_name' => $groups[mt_rand(0, count($groups) - 1)],
        'ref_no'     => sprintf('K%04d', $i),
    ]);
}
db()->commit();
echo "Etkinlik #$eventId aktif, 240 kişi eklendi.\n";
