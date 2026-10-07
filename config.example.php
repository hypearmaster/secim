<?php
// Bu dosyayı config.php olarak kopyalayın ve doldurun. config.php git'e girmez.
return [
    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'secim',
        'user'    => 'root',
        'pass'    => '',
    ],
    // Uygulamanın web yolu (sonunda / yok). Docroot doğrudan public/ ise '' yazın.
    'base_url' => '/secim/public',
    'app_name' => 'Katılım Takip',
    'timezone' => 'Europe/Istanbul',
    // Canlıda MUTLAKA false.
    'debug'    => false,
    // Onay ekibi kendi işaretini kaç dakika içinde geri alabilir.
    'unmark_window_minutes' => 5,
    // Giriş denemesi sınırı (15 dakikada)
    'login_max_per_user' => 5,
    'login_max_per_ip'   => 30,
    // "Bizimle mi?" sütunu seçenekleri: anahtar => [etiket, renk(success|warning|danger|muted)].
    // Anahtarlar DB'ye yazılır; bir anahtarı silerseniz o değerdeki kayıtlar "Belirsiz" görünür.
    'stance_options' => [
        'bizimle'  => ['Bizimle', 'success'],
        'kararsiz' => ['Kararsız', 'warning'],
        'karsi'    => ['Karşı', 'danger'],
        'bilinmiyor' => ['Bilinmiyor', 'muted'],
    ],
];
