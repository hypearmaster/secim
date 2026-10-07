<?php
declare(strict_types=1);

// DB'ye ve gerçek oy kayıtlarına dokunmadan eski kurulum ayarlarını doğrular.
require __DIR__ . '/../src/helpers.php';
require __DIR__ . '/../src/votes.php';

$GLOBALS['__config'] = [];
$defaults = vote_options();
if (array_keys($defaults) !== ['mavi', 'kirmizi']) {
    throw new RuntimeException('Eski config ile sayım butonları eksik.');
}
$GLOBALS['__config'] = ['vote_options' => ['yesil' => ['Yeşil', '#16a34a']]];
if (vote_options() !== ['yesil' => ['label' => 'Yeşil', 'color' => '#16a34a']]) {
    throw new RuntimeException('Özel oy seçenekleri korunmadı.');
}
echo "OK: eski config varsayılanları ve özel seçenekler\n";
