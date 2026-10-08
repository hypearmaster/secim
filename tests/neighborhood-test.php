<?php
declare(strict_types=1);

// Mahalle çıkarma / transfer metni / adres gizliliği birim testi. VERİTABANI GEREKMEZ (sadece saf fonksiyonlar).
//   php tests/neighborhood-test.php
if (PHP_SAPI !== 'cli') {
    exit;
}
require __DIR__ . '/../src/bootstrap.php';
require APP_ROOT . '/src/spreadsheet.php';

$fail = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $fail;
    echo ($ok ? "  OK   " : "  FAIL ") . $name . ($ok || $detail === '' ? '' : " — $detail") . "\n";
    if (!$ok) $fail++;
}

// ---- extract_neighborhood: adres -> sadece mahalle
$cases = [
    'klasik: mahalle başta, KEMER/ANTALYA sonda'  => ['BELDİBİ MAH. ATATÜRK CAD. NO:5 KEMER/ANTALYA', 'Beldibi Mah.'],
    'Mahallesi (küçük/büyük karışık)'             => ['Çamyuva Mahallesi Sahil Yolu No:12/3 Kemer', 'Çamyuva Mah.'],
    'mahalle ortada, NO:5 öncesinde durur'        => ['ATATÜRK CAD. NO:5 KİRİŞ MAH. KEMER / ANTALYA', 'Kiriş Mah.'],
    'sıra sayılı ad (100. Yıl)'                   => ['100. YIL MAH. 1234 SK. NO:7', '100. Yıl Mah.'],
    'küçük harf ı/İ (ışıklar)'                    => ['ışıklar mah. gül sok. no 4', 'Işıklar Mah.'],
    'bitişik yazım MAH.Gül (nokta sonrası boşluk yok)' => ['Hacı Bayram Veli Mah.Gül Sk. No:3', 'Hacı Bayram Veli Mah.'],
    'Mh. kısaltması'                              => ['ÇAKIRLAR MH. 12. SOKAK', 'Çakırlar Mah.'],
    'noktasız MAH'                                => ['AKSU MAH ATATÜRK CD NO 5 MURATPAŞA', 'Aksu Mah.'],
    'virgülle ayrılmış, önünde ofis bilgisi'      => ['Ofis 3 Kat 2, YEŞİLBAHÇE MAH., Muratpaşa/Antalya', 'Yeşilbahçe Mah.'],
    'önek biçimi: "Mahallesi: X"'                 => ['Mahallesi: Tekirova, Kemer', 'Tekirova Mah.'],
    'sadece mahalle yazılmış'                     => ['TEKİROVA MAHALLESİ', 'Tekirova Mah.'],
    'ASCII yazılmış (gocmen mahallesi)' => ['gocmen mahallesi ataturk cd 1', 'Gocmen Mah.'],
];
foreach ($cases as $label => [$addr, $want]) {
    $got = extract_neighborhood($addr);
    check("mahalle: $label", $got === $want, "girdi=\"$addr\" beklenen=\"$want\" gelen=" . var_export($got, true));
}
// Mahallesiz adresler -> null
foreach (['ATATÜRK BULVARI NO:5 KEMER/ANTALYA', 'Mahmutlar Mevkii Sanayi Sitesi D:4', '', '   ', null, 'MAH.', 'NO:5 MAH. KEMER'] as $addr) {
    check('mahallesiz -> null: ' . var_export($addr, true), extract_neighborhood($addr) === null, var_export(extract_neighborhood($addr), true));
}
// En fazla 4 kelime (cadde/sokak ayracı yoksa uzun öneki yutmaz)
$long = extract_neighborhood('ALİ BEY ÇAMLI ORTA YENİ MAH.');
check('uzun öneklerde en fazla 4 kelime', $long === 'Bey Çamlı Orta Yeni Mah.', var_export($long, true));

// ---- normalize_neighborhood: ayrı "Mahalle" sütunu
check('ayrı sütun: yalın ad', normalize_neighborhood('BELDİBİ') === 'Beldibi Mah.');
check('ayrı sütun: Mahallesi yazılı', normalize_neighborhood('beldibi mahallesi') === 'Beldibi Mah.');
check('ayrı sütun: zaten Mah.', normalize_neighborhood('Bağlar Mah.') === 'Bağlar Mah.');
check('ayrı sütun: boş -> null', normalize_neighborhood('  ') === null && normalize_neighborhood(null) === null);

// ---- Türkçe başlık düzeni
check('tr_title: İ/ı', tr_title('İSTİKLAL ışık') === 'İstiklal Işık', tr_title('İSTİKLAL ışık'));

// ---- Gizlilik: tam adres SADECE address alanında saklanır; mail/TC/ortak/IBAN yine alınamaz
$addr = 'BELDİBİ MAH. ATATÜRK CAD. NO:5 KEMER/ANTALYA';
check('adres sütunu sadece "address"/"alma" olabilir', allowed_field('Adres', 'address') && allowed_field('Adres', 'ignore')
    && !allowed_field('Adres', 'extra') && !allowed_field('Adres', 'full_name') && !allowed_field('Adres', 'neighborhood'));
check('adres sütunu varsayılan olarak mahalleye çevrilir', guess_field('Adres') === 'address' && guess_field('İş Adresi') === 'address');
check('e-posta adresi adres sayılmaz (kilitli kalır)', !is_address_header('E-Posta Adresi') && guess_field('E-Posta Adresi') === 'ignore'
    && !allowed_field('E-Posta Adresi', 'address'));
check('başka sütun "address"e eşlenemez', !allowed_field('Unvan', 'address') && !allowed_field('Gsm', 'address'));
check('Mahalle sütunu mahalleye, İlçe bölgeye eşlenir', guess_field('Mahalle') === 'neighborhood' && guess_field('İlçe') === 'region');
check('Transfer sütunu tanınır', guess_field('Transfer') === 'transfer');

$bp = build_people(['Unvan', 'Adres', 'Transfer'], [['A FİRMA', $addr, 'Rakip alıyor'], ['B FİRMA', 'ATATÜRK BLV. NO:1 KEMER', 'bilmem'], ['C FİRMA', null, null]],
    ['full_name', 'address', 'transfer'], null)['people'];
check('build_people: mahalle türetilir', array_map(fn($p) => $p['neighborhood'] ?? null, $bp) === ['Beldibi Mah.', null, null], json_encode(array_column($bp, 'neighborhood'), JSON_UNESCAPED_UNICODE));
check('build_people: tam adres address alanında, extra\'ya sızmaz', ($bp[0]['address'] ?? null) === $addr && empty($bp[0]['extra']) && ($bp[2]['address'] ?? null) === null, json_encode($bp, JSON_UNESCAPED_UNICODE));
check('kişisel sütunlar hâlâ kilitli (mail/TC/ortak/IBAN)', !allowed_field('Mail', 'extra') && !allowed_field('TC Kimlik No', 'extra')
    && !allowed_field('Ortaklar', 'extra') && !allowed_field('IBAN', 'extra') && guess_field('Mail') === 'ignore');
check('build_people: transfer metni -> anahtar, tanınmayan -> null', array_map(fn($p) => $p['transfer'] ?? null, $bp) === ['rakip', null, null], json_encode(array_column($bp, 'transfer')));

$er = enrich_rows([['101', 'BELDİBİ MAH. X CAD.', 'Biz alıyoruz', '0532 111 22 33'], [null, null, 'saçma', null]], ['ref_no', 'address', 'transfer', 'phone']);
check('enrich_rows: sicil + mahalle + transfer + telefon', $er[0] === ['ref_no' => '101', 'name' => null, 'phone' => '+905321112233', 'neighborhood' => 'Beldibi Mah.', 'address' => 'BELDİBİ MAH. X CAD.', 'transfer' => 'aliyoruz'], json_encode($er[0], JSON_UNESCAPED_UNICODE));
check('enrich_rows: boş/tanınmayan değer null', $er[1]['neighborhood'] === null && $er[1]['transfer'] === null && $er[1]['phone'] === null);
check('enrich_rows: boş adres null', $er[1]['address'] === null);

// ---- Transfer
check('transfer_from_text: anahtar / etiket / büyük harf', transfer_from_text('aliyoruz') === 'aliyoruz' && transfer_from_text('RAKİP ALIYOR') === 'rakip'
    && transfer_from_text('Almıyoruz') === 'almiyoruz' && transfer_from_text('Biz alıyoruz') === 'aliyoruz');
check('transfer_from_text: belirsiz / boş / tanınmayan -> null', transfer_from_text('Belirsiz') === null && transfer_from_text('') === null && transfer_from_text('xyz') === null);
check('transfer_view: null = Belirsiz', transfer_view(null)['label'] === 'Belirsiz' && transfer_view('rakip')['color'] === 'danger' && transfer_view('yok')['value'] === null);
check('transfer seçenekleri belirsiz dahil', array_keys(transfer_options()) === ['aliyoruz', 'almiyoruz', 'rakip', 'belirsiz'], implode(',', array_keys(transfer_options())));

echo $fail ? "\n$fail test BAŞARISIZ\n" : "\nTüm testler geçti\n";
exit($fail ? 1 : 0);
