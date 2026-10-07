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
    // Adres çubuğunda görünen yol (sonunda / yok). Kökteki .htaccess istekleri public/'e
    // aktarır: proje gencderi.com/secim ise '/secim'. Docroot doğrudan public/ ise ''.
    'base_url' => '/secim',
    'app_name' => 'Katılım Takip',
    'timezone' => 'Europe/Istanbul',
    // Canlıda MUTLAKA false.
    'debug'    => false,
    // Onay ekibi kendi işaretini kaç dakika içinde geri alabilir.
    'unmark_window_minutes' => 5,
    // Giriş denemesi sınırı (15 dakikada)
    'login_max_per_user' => 5,
    'login_max_per_ip'   => 30,
    // "Bu cihazda oturumum açık kalsın": kaç gün (tarayıcı kapansa da giriş istemez).
    // Şifre değişince, kullanıcı pasife alınınca ya da "Tüm cihazlardan çıkar" ile hepsi düşer.
    'remember_days' => 30,
    // Elle eklenen kişide sicil no yoksa yazılan değer (firma gruplamasında sicil sayılmaz).
    'placeholder_ref' => '11111111',
    // "Bizimle mi?" sütunu seçenekleri: anahtar => [etiket, renk(success|warning|danger|muted)].
    // Anahtarlar DB'ye yazılır; bir anahtarı silerseniz o değerdeki kayıtlar "Belirsiz" görünür.
    'stance_options' => [
        'bizimle'  => ['Bizimle', 'success'],
        'kararsiz' => ['Kararsız', 'warning'],
        'karsi'    => ['Rakip', 'danger'],
        'bilinmiyor' => ['Bilinmiyor', 'muted'],
    ],
    // Bizimkiler listesinde eşleşenlere yazılan "Bizimle mi?" anahtarı; onay ekranında mavi zemin.
    'supporter_stance' => 'bizimle',
    // Oy sayacı: büyük butonlar. anahtar => [etiket, renk(hex)]. Soldan sağa ekrandaki sıra.
    // İleride beyaz/yeşil vb. eklemek için satır ekleyin; sayfa kendiliğinden büyür.
    'vote_options' => [
        'mavi'    => ['Mavi', '#1d4ed8'],
        'kirmizi' => ['Kırmızı', '#dc2626'],
    ],
];
