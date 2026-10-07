<?php
declare(strict_types=1);

// Çekirdek davranış testi (kapsam, işaretleme, geri alma, arama, Bizimle mi?).
// KENDİ geçici listesini ve kullanıcılarını açar, bitince siler; gerçek listedeki
// kişilere ve işaretlere DOKUNMAZ. Çalışırken (birkaç saniye) test listesi aktif olur;
// bu yüzden işaretleme yapılan bir listede --force olmadan çalışmaz.
//   php tests/core-test.php
if (PHP_SAPI !== 'cli') {
    exit;
}
require __DIR__ . '/../src/bootstrap.php';

$prevEvent = active_event();
if ($prevEvent && !in_array('--force', $argv, true)
    && (int) q('SELECT COUNT(*) FROM people WHERE event_id = ? AND attended_at IS NOT NULL', [$prevEvent['id']])->fetchColumn() > 0) {
    fwrite(STDERR, "Aktif listede işaretleme var ({$prevEvent['name']}). Test birkaç saniye başka liste açacağı için durdu.\n"
        . "Etkinlik bittiyse: php tests/core-test.php --force\n");
    exit(2);
}

$fail = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $fail;
    echo ($ok ? "  OK   " : "  FAIL ") . $name . ($ok || $detail === '' ? '' : " — $detail") . "\n";
    if (!$ok) $fail++;
}
function user_by(string $username): array
{
    $u = q('SELECT id, username, full_name, role, region, is_active FROM users WHERE username = ?', [$username])->fetch();
    if (!$u) { fwrite(STDERR, "Test kullanıcısı yok: $username\n"); exit(1); }
    $u['id'] = (int) $u['id'];
    return $u;
}

function make_test_user(string $username, string $role, ?string $region): array
{
    q('INSERT INTO users (username, password_hash, full_name, role, region, created_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())',
        [$username, password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), 'Test ' . ROLES[$role], $role, $region]);
    return user_by($username);
}

// ---- Geçici test verisi (bitince register_shutdown_function siler, hata olsa bile)
$tag = 'zztest_' . bin2hex(random_bytes(3));
$onay = make_test_user("{$tag}_onay", 'onayci', 'Merkez');   // bölge kısıtlı
$yon = make_test_user("{$tag}_yon", 'yonetici', null);        // tüm bölgeler
$testEvent = create_event("__TEST__ $tag", $yon['id']);       // aktif yapar
register_shutdown_function(function () use ($testEvent, $prevEvent, $onay, $yon) {
    q('DELETE FROM vote_log WHERE event_id = ?', [$testEvent]);
    q('DELETE FROM gifts WHERE event_id = ?', [$testEvent]);
    q('DELETE FROM attendance_log WHERE event_id = ?', [$testEvent]);
    q('DELETE FROM supporters WHERE event_id = ?', [$testEvent]); // supporter_people CASCADE
    q('DELETE FROM people WHERE event_id = ?', [$testEvent]);
    q('DELETE FROM events WHERE id = ?', [$testEvent]);
    q('DELETE FROM remember_tokens WHERE user_id IN (?, ?)', [$onay['id'], $yon['id']]);
    q('DELETE FROM audit_log WHERE user_id IN (?, ?)', [$onay['id'], $yon['id']]);
    q('DELETE FROM users WHERE id IN (?, ?)', [$onay['id'], $yon['id']]);
    if ($prevEvent) {
        activate_event($prevEvent['id']);
    }
    echo '  (geçici test verisi silindi' . ($prevEvent ? "; aktif liste geri alındı: {$prevEvent['name']}" : '') . ")\n";
});
foreach ([['Merkez', 'A Grubu'], ['Merkez', null], ['Merkez', 'B Grubu'], ['Merkez', null],
          ['Kuzey', 'A Grubu'], ['Kuzey', null], ['Güney', 'B Grubu']] as $i => [$reg, $grp]) {
    insert_person($testEvent, null, ['full_name' => "Test Kişi $i", 'authority' => 'Yetkili Ad', 'region' => $reg, 'group_name' => $grp, 'ref_no' => "T$i"]);
}
$ev = active_event(true);
check('geçici test listesi aktif', $ev !== null && $ev['id'] === $testEvent);

// Kapsam
$r = search_people($onay, [], 200);
$regions = array_unique(array_column($r['items'], 'region'));
check('onayci sadece kendi bölgesini görür', $regions === ['Merkez'], implode(',', $regions));
$all = scope_stats($yon)['total'];
check('yönetici hepsini görür', $all === (int) q('SELECT COUNT(*) FROM people WHERE event_id = ?', [$ev['id']])->fetchColumn());

$merkezId = (int) q("SELECT id FROM people WHERE event_id = ? AND region = 'Merkez' ORDER BY id LIMIT 1", [$ev['id']])->fetchColumn();
$merkez2 = (int) q("SELECT id FROM people WHERE event_id = ? AND region = 'Merkez' ORDER BY id LIMIT 1 OFFSET 1", [$ev['id']])->fetchColumn();
$kuzeyId = (int) q("SELECT id FROM people WHERE event_id = ? AND region = 'Kuzey' ORDER BY id LIMIT 1", [$ev['id']])->fetchColumn();

// İşaretleme
$m = mark_person($onay, $merkezId);
check('onayci kendi bölgesinde işaretler', $m['ok'] && $m['person']['attended'] && $m['person']['mine']);
$m2 = mark_person($yon, $merkezId);
check('ikinci işaret 409 + kim işaretledi', !$m2['ok'] && $m2['status'] === 409 && str_contains($m2['error'], $onay['full_name']), $m2['error'] ?? '');
$m3 = mark_person($onay, $kuzeyId);
check('başka bölge 404 (varlığı bile sızmaz)', !$m3['ok'] && $m3['status'] === 404);
$m4 = mark_person($onay, 99999999);
check('olmayan kişi 404', $m4['status'] === 404);

// Geri alma
$y = mark_person($yon, $merkez2);
$u1 = unmark_person($onay, $merkez2);
check('onayci başkasının işaretini geri alamaz', !$u1['ok'] && $u1['status'] === 403);
$u2 = unmark_person($onay, $merkezId);
check('onayci kendi işaretini süre içinde geri alır', $u2['ok'] && !$u2['person']['attended']);
$u3 = unmark_person($onay, $merkezId);
check('işaretsizi geri alma 409', $u3['status'] === 409);
mark_person($onay, $merkezId);
q('UPDATE people SET attended_at = UTC_TIMESTAMP() - INTERVAL 10 MINUTE WHERE id = ?', [$merkezId]);
$u4 = unmark_person($onay, $merkezId);
check('süre dolunca onayci geri alamaz', !$u4['ok'] && $u4['status'] === 403);
$u5 = unmark_person($yon, $merkezId);
check('yönetici herkesinkini geri alır', $u5['ok']);

$logs = (int) q('SELECT COUNT(*) FROM attendance_log WHERE person_id IN (?, ?)', [$merkezId, $merkez2])->fetchColumn();
check('her başarılı işlem loglandı (>=5)', $logs >= 5, "log=$logs");

// Arama
q("UPDATE people SET full_name = 'Şükrü Işıl Çağlar', search_key = ? WHERE id = ?", [search_key('Şükrü Işıl Çağlar', null), $merkez2]);
foreach (['sukru', 'ŞÜKRÜ', 'isil cag', 'IŞIL'] as $term) {
    $ids = array_column(search_people($onay, ['q' => $term])['items'], 'id');
    check("arama '$term' bulur", in_array($merkez2, $ids, true));
}
$pct = search_people($onay, ['q' => '%'])['total'];
check("arama '%' boş arama gibi davranır (joker değil)", $pct === scope_stats($onay)['total'], "total=$pct");
$came = search_people($yon, ['status' => 'came'])['items'];
check('durum filtresi', count(array_filter($came, fn($p) => !$p['attended'])) === 0);
$noGroup = search_people($yon, ['group' => NONE_VALUE], 200)['items'];
check('grupsuz filtresi', count($noGroup) > 0 && count(array_filter($noGroup, fn($p) => $p['group_name'] !== null)) === 0);

// "Bizimle mi?"
$s1 = set_stance($onay, $merkezId, 'bizimle');
check('onayci stance değiştiremez', !$s1['ok'] && $s1['status'] === 403);
check('onayciye stance gönderilmez', search_people($onay, [])['items'][0]['stance'] === null);
$s2 = set_stance($yon, $merkezId, 'bizimle');
check('yönetici stance ayarlar', $s2['ok'] && $s2['person']['stance']['value'] === 'bizimle');
$s3 = set_stance($yon, $merkezId, 'olmayan');
check('geçersiz stance 422', $s3['status'] === 422);
$ids = array_column(search_people($yon, ['stance' => 'bizimle'], 200)['items'], 'id');
check('stance filtresi', in_array($merkezId, $ids, true) && count($ids) >= 1);
$s4 = set_stance($yon, $merkezId, null);
check('stance temizlenir', $s4['ok'] && $s4['person']['stance']['value'] === null);
check('names_only doğum tarihi/yerini atar', names_only('MEHMET GENÇ-06.01.1980-ÖMERLİ,ORHAN DOĞAN-10.02.1963-ŞİRAN') === 'MEHMET GENÇ, ORHAN DOĞAN');

// Aynı firma / şubeler tek kart
$b1 = insert_person($testEvent, null, ['full_name' => 'ZİNCİR MAĞAZACILIK A.Ş. AGORA ŞUBESİ', 'authority' => 'Erdal Sağlam', 'region' => 'Merkez', 'ref_no' => 'Z1']);
$b2 = insert_person($testEvent, null, ['full_name' => 'ZİNCİR MAĞAZACILIK A.Ş. KEPEZ ŞUBESİ', 'authority' => 'Erdal Sağlam', 'region' => 'Merkez', 'ref_no' => 'Z2']);
$b3 = insert_person($testEvent, null, ['full_name' => 'ZİNCİR MAĞAZACILIK A.Ş. KUZEY ŞUBESİ', 'authority' => 'Erdal Sağlam', 'region' => 'Kuzey', 'ref_no' => 'Z3']);
$b4 = insert_person($testEvent, null, ['full_name' => 'SAĞLAM İNŞAAT LTD ŞTİ', 'authority' => 'Erdal Sağlam', 'region' => 'Merkez', 'ref_no' => 'Z4']);
$cards = search_people($yon, ['q' => 'erdal saglam'])['items'];
check('şubeler tek kart, başka şirketi ayrı kart', count($cards) === 2 && in_array(3, array_column($cards, 'count'), true));
$onayCards = search_people($onay, ['q' => 'zincir'])['items'];
check('onaycı sadece kendi bölgesindeki şubeleri görür', count($onayCards) === 1 && $onayCards[0]['count'] === 2);
$g = mark_person($onay, $b2);
$attOf = fn(int $id) => q('SELECT attended_at IS NOT NULL FROM people WHERE id = ?', [$id])->fetchColumn() == 1;
check('GELDİ kapsamdaki tüm şubeleri işaretler', $g['ok'] && $g['marked'] === 2 && $attOf($b1) && $attOf($b2));
check('kapsam dışı şube (başka bölge) işaretlenmez', !$attOf($b3));
check('başka şirketi etkilenmez', !$attOf($b4));
$yg = search_people($yon, ['q' => 'zincir'])['items'][0];
check('yönetici kısmi geleni görür (2/3)', $yg['came_count'] === 2 && $yg['count'] === 3 && !$yg['attended']);
$g2 = mark_person($yon, $b1);
check('yönetici kalanı işaretler', $g2['ok'] && $g2['marked'] === 1 && $attOf($b3));
check('hepsi geldiyse ikinci GELDİ 409', mark_person($yon, $b3)['status'] === 409);
$u = unmark_person($onay, $b1);
check('onaycı geri alma: sadece kendi işaretlediği şubeler', $u['ok'] && !$attOf($b1) && !$attOf($b2) && $attOf($b3));
$st = set_stance($yon, $b1, 'bizimle');
check('Bizimle mi? tüm şubelere yazılır', $st['ok'] && q("SELECT COUNT(*) FROM people WHERE id IN (?, ?, ?) AND stance = 'bizimle'", [$b1, $b2, $b3])->fetchColumn() == 3);
check('Bizimle mi? başka şirkete yazılmaz', q('SELECT stance FROM people WHERE id = ?', [$b4])->fetchColumn() === null);
check('yetkilisiz kayıtlar birleşmez', firm_key('AHMET KAYA', null, '1') !== firm_key('AHMET KAYA', null, '2'));

// Bizimkiler (isim listesi eşleştirme)
require APP_ROOT . '/src/spreadsheet.php';
require APP_ROOT . '/src/supporters.php';
$p1 = insert_person($testEvent, null, ['full_name' => 'ÇAĞLAR GIDA LTD ŞTİ', 'authority' => 'Mehmet Genç, Orhan Doğan', 'region' => 'Merkez']);
$p2 = insert_person($testEvent, null, ['full_name' => 'GENÇ TURİZM A.Ş.', 'authority' => 'Mehmet Genç', 'region' => 'Kuzey']);
$p3 = insert_person($testEvent, null, ['full_name' => 'AHMET YILDIRIM İNŞAAT TİCARET LTD ŞTİ', 'region' => 'Merkez']);
$p4 = insert_person($testEvent, null, ['full_name' => 'ABDULLAH BOYNUKARA', 'region' => 'Merkez']);
$p5 = insert_person($testEvent, null, ['full_name' => 'SELİN KAYA', 'authority' => 'Selin Kaya', 'region' => 'Merkez']);
$idx = supporter_index($testEvent);
[$st, $ids] = match_name('mehmet GENÇ', $idx);
check('tam eşleşme, iki firmanın yetkilisi → ikisi de', $st === 'matched' && count($ids) === 2 && in_array($p1, $ids) && in_array($p2, $ids));
check('firma adı kişinin adını taşıyor → tahmin', match_name('Ahmet Yıldırım', $idx) === ['suggested', [$p3]]);
check('tek harf farkı → tahmin', match_name('Abdullah Boynikara', $idx) === ['suggested', [$p4]]);
check('ikinci ad fazla → tahmin', match_name('Selin Ayşe Kaya', $idx)[0] === 'suggested');
check('sadece soyad tutuyor → bulunamadı', match_name('Hasan Genç', $idx)[0] === 'none');
$s = import_supporters($testEvent, ['Mehmet Genç', 'Ahmet Yıldırım', 'Hiç Olmayan Kişi'], $yon['id']);
check('yükleme sayımı', $s === ['matched' => 1, 'suggested' => 1, 'none' => 1], json_encode($s));
$stanceOf = fn(int $id) => q('SELECT stance FROM people WHERE id = ?', [$id])->fetchColumn();
check('tam eşleşen "Bizimle" oldu', $stanceOf($p1) === 'bizimle' && $stanceOf($p2) === 'bizimle');
check('tahmin onaysız "Bizimle" olmaz', $stanceOf($p3) === null);
$sugg = q("SELECT * FROM supporters WHERE event_id = ? AND status = 'suggested'", [$testEvent])->fetch();
confirm_supporter($sugg);
check('tahmin onaylanınca "Bizimle"', $stanceOf($p3) === 'bizimle');
$mg = q("SELECT * FROM supporters WHERE event_id = ? AND name = 'Mehmet Genç'", [$testEvent])->fetch();
unlink_supporter($mg, $p2);
check('bağ kaldırılınca "Bizimle" geri alınır', $stanceOf($p2) === null && $stanceOf($p1) === 'bizimle');
$none = q("SELECT * FROM supporters WHERE event_id = ? AND status = 'none'", [$testEvent])->fetch();
check('başka etkinliğin kişisi bağlanamaz', !link_supporter($none, (int) q('SELECT id FROM people WHERE event_id <> ? LIMIT 1', [$testEvent])->fetchColumn() ?: 0));
check('elle bağlama', link_supporter($none, $p5) && $stanceOf($p5) === 'bizimle');
q('UPDATE people SET stance = NULL WHERE id IN (?, ?, ?)', [$b1, $b2, $b3]);
check('elle bağlama firmanın tüm şubelerini bağlar', link_supporter($none, $b2)
    && $stanceOf($b1) === 'bizimle' && $stanceOf($b2) === 'bizimle' && $stanceOf($b3) === 'bizimle' && $stanceOf($b4) === null);
q('UPDATE people SET stance = ? WHERE id = ?', ['bizimle', $p4]); // elle "Bizimle" (listeden bağımsız)
clear_supporters($testEvent);
check('liste silinince verdiği işaretler kalkar', $stanceOf($p1) === null && $stanceOf($p3) === null && $stanceOf($p5) === null);
check('elle verilen "Bizimle" korunur', $stanceOf($p4) === 'bizimle');
// "Bizimle" etiketi = Bizimkiler listesi (Enes'in bulduğu: elle Bizimle işaretlenen sayılmıyordu)
q('DELETE FROM supporters WHERE event_id = ?', [$testEvent]);           // önceki bölümlerden temiz sayfa
q('UPDATE people SET stance = NULL WHERE event_id = ?', [$testEvent]);
$m1 = insert_person($testEvent, null, ['full_name' => 'ELLE TİCARET LTD', 'authority' => 'Elle Kişi', 'region' => 'Merkez']);
$m2 = insert_person($testEvent, null, ['full_name' => 'LİSTE TİCARET LTD', 'authority' => 'Liste Kişi', 'region' => 'Merkez']);
import_supporters($testEvent, ['Liste Kişi'], $yon['id']);
set_stance($yon, $m1, 'bizimle');                     // listede yok, elle işaretlendi
mark_person($yon, $m1);
mark_person($yon, $m2);
$st = supporter_stats($testEvent);
check('elle Bizimle işaretlenen Bizimkiler\'de sayılır (2 gelen)', $st['came'] === 2 && $st['matched'] === 2, json_encode($st));
check('elle kayıt "manual" kaynaklı', q("SELECT source FROM supporters WHERE event_id = ? AND name = 'Elle Kişi'", [$testEvent])->fetchColumn() === 'manual');
import_supporters($testEvent, ['Liste Kişi'], $yon['id']); // listeyi yeniden yükle
check('liste yeniden yüklenince elle kayıt korunur', supporter_stats($testEvent)['matched'] === 2 && $stanceOf($m1) === 'bizimle');
set_stance($yon, $m1, 'kararsiz');
check('Bizimle kaldırılınca listeden düşer', supporter_stats($testEvent)['matched'] === 1
    && !q("SELECT COUNT(*) FROM supporters WHERE event_id = ? AND name = 'Elle Kişi'", [$testEvent])->fetchColumn());
set_stance($yon, $m1, 'bizimle');
import_supporters($testEvent, ['Liste Kişi', 'Elle Kişi'], $yon['id']);
check('listede de varsa elle kayıt tekrarlanmaz', (int) q("SELECT COUNT(*) FROM supporters WHERE event_id = ? AND search_key = 'elle kisi'", [$testEvent])->fetchColumn() === 1);
// Sil / Bağımsız kaydet
import_supporters($testEvent, ['Liste Kişi', 'Firmasız Biri', 'İSİMSİZ'], $yon['id']);
$fb = q("SELECT * FROM supporters WHERE event_id = ? AND name = 'Firmasız Biri'", [$testEvent])->fetch();
$isz = q("SELECT * FROM supporters WHERE event_id = ? AND name = 'İSİMSİZ'", [$testEvent])->fetch();
check('firmasız isim bulunamadı', $fb['status'] === 'none' && $isz['status'] === 'none');
$ip = make_independent($fb);
check('bağımsız kaydet: Bağımsız grubunda kişi + Bizimle', q('SELECT group_name FROM people WHERE id = ?', [$ip])->fetchColumn() === INDEPENDENT_GROUP && $stanceOf($ip) === 'bizimle');
check('bağımsız kişi onay ekranında aranır ve işaretlenir', count(search_people($onay, ['q' => 'firmasiz biri'])['items']) === 1 && mark_person($onay, $ip)['ok']);
check('bağımsız gelen Bizimkiler\'de sayılır', supporter_stats($testEvent)['came'] >= 2);
delete_supporter($isz);
check('sil: isim listeden kalkar', !q('SELECT COUNT(*) FROM supporters WHERE id = ?', [$isz['id']])->fetchColumn());
$fb = q('SELECT * FROM supporters WHERE id = ?', [$fb['id']])->fetch();
check('GELDİ kaydı olan bağımsız kişi silinmez', delete_supporter($fb) === 1 && q('SELECT COUNT(*) FROM people WHERE id = ?', [$ip])->fetchColumn() == 1);
$matchedSup = q("SELECT * FROM supporters WHERE event_id = ? AND status = 'matched' LIMIT 1", [$testEvent])->fetch();
check('eşleşmiş kayıt bağımsız yapılamaz (tahmin onayı atlanmasın)', (function () use ($matchedSup) {
    try { make_independent($matchedSup); return false; } catch (InvalidArgumentException $e) { return true; }
})());

// Telefon
foreach (['0532 123 45 67' => '+905321234567', '5321234567' => '+905321234567', '+90 (532) 123-45-67' => '+905321234567',
          '905321234567' => '+905321234567', '+90 242 321 31 01' => '+902423213101', '0049 30 1234567' => '+49301234567',
          '12345' => null, 'yok' => null, '' => null] as $in => $want) {
    check("telefon '$in' -> " . var_export($want, true), normalize_phone($in) === $want, var_export(normalize_phone($in), true));
}
check('telefon görünümü', phone_display('+905321234567') === '0532 123 45 67');
$tp = insert_person($testEvent, null, ['full_name' => 'TELEFON TİCARET LTD', 'authority' => 'Tel Kişi', 'region' => 'Merkez', 'ref_no' => 'TP1', 'phone' => '0242 321 31 01']);
insert_person($testEvent, null, ['full_name' => 'TELEFON TİCARET LTD ŞUBE', 'authority' => 'Tel Kişi', 'region' => 'Merkez', 'ref_no' => 'TP2', 'phone' => '05321234567']);
$tc = search_people($onay, ['q' => 'tel kisi'])['items'][0] ?? null;
check('kartta telefon (cep öncelikli)', $tc && $tc['phone'] === '+905321234567' && $tc['phone_text'] === '0532 123 45 67', json_encode($tc['phone'] ?? null));
check('telefon import: Gsm/Tel sütunu tanınır, mail tanınmaz', guess_field('Gsm') === 'phone' && guess_field('Cep Tel') === 'phone'
    && guess_field('Tel') === 'phone' && guess_field('Mail') === 'ignore' && guess_field('Otel Adı') === 'ignore');
check('telefon sütunu başka alana eşlenemez', !allowed_field('Gsm', 'full_name') && allowed_field('Gsm', 'phone')
    && !allowed_field('Unvan', 'phone') && !allowed_field('Adres', 'extra'));
$bp = build_people(['Unvan', 'Tel', 'Gsm'], [['A FİRMA', '+90 242 111 22 33', '5321112233'], ['B FİRMA', '0242 111 22 33', null], ['C FİRMA', 'yok', 'yok']],
    ['full_name', 'phone', 'phone'], null)['people'];
check('import: cep varsa cep, yoksa sabit, geçersizse boş', array_column($bp, 'phone') === ['+905321112233', '+902421112233', null], json_encode(array_column($bp, 'phone')));
$up = update_phones($testEvent, [['ref_no' => 'TP1', 'phone' => '+905559998877'], ['ref_no' => 'YOK1', 'phone' => '+905550000000'],
    ['ref_no' => 'TP2', 'phone' => null], ['ref_no' => '11111111', 'phone' => '+905551111111']]);
check('sadece telefon güncelle', $up === ['updated' => 1, 'unchanged' => 0, 'not_found' => 2, 'no_phone' => 1]
    && q('SELECT phone FROM people WHERE id = ?', [$tp])->fetchColumn() === '+905559998877', json_encode($up));

// Elle kişi ekleme
$a0 = add_person($onay, ['name' => 'Yeni Biri']);
check('onaycı kişi ekleyemez', !$a0['ok'] && $a0['status'] === 403);
$a1 = add_person($yon, ['name' => 'Yeni Biri', 'phone' => '0533 000 11 22', 'region' => 'Merkez', 'group' => 'A Grubu']);
check('yönetici sicilsiz kişi ekler -> 11111111', $a1['ok'] && $a1['person']['ref_no'] === '11111111' && $a1['person']['phone'] === '+905330001122'
    && $a1['person']['full_name'] === 'Yeni Biri' && $a1['person']['authority'] === null, json_encode($a1));
$a2 = add_person($yon, ['name' => 'Başka Biri', 'firm' => 'BAŞKA TİCARET', 'region' => 'Merkez']);
check('firmalı ekleme: kartta ad = kişi, altında firma', $a2['ok'] && $a2['person']['authority'] === 'Başka Biri' && $a2['person']['full_name'] === 'BAŞKA TİCARET');
$a2b = add_person($yon, ['name' => 'Üçüncü Kişi', 'region' => 'Merkez']);
check('sicilsiz kişiler tek kartta birleşmez', $a2b['ok'] && $a2b['person']['count'] === 1 && $a1['person']['id'] !== $a2b['person']['id']);
$a3 = add_person($yon, ['name' => 'YENİ BİRİ']);
check('aynı isim uyarısı (409 + benzerler)', !$a3['ok'] && $a3['status'] === 409 && !empty($a3['similar']), json_encode($a3));
check('benzer ama farklı isim uyarı vermez', add_person($yon, ['name' => 'Yeni Birim Ali', 'region' => 'Merkez'])['ok']);
$a4 = add_person($yon, ['name' => 'Yeni', 'ref_no' => 'TP1']);
check('aynı sicil uyarısı', !$a4['ok'] && $a4['status'] === 409);
$a5 = add_person($yon, ['name' => 'YENİ BİRİ'], true);
check('yine de ekle (force)', $a5['ok']);
check('geçersiz telefon 422', add_person($yon, ['name' => 'X Y', 'phone' => '123'])['status'] === 422);
check('geçersiz sicil 422', add_person($yon, ['name' => 'X Y', 'ref_no' => "1'; DROP"])['status'] === 422);
check('ad zorunlu 422', add_person($yon, ['name' => '  '])['status'] === 422);
check('eklenen kişi aranır ve GELDİ işaretlenir', count(search_people($onay, ['q' => 'baska biri'])['items']) === 1 && mark_person($onay, $a2['person']['id'])['ok']);
check('arama yetkisi olmayana numara gitmez', person_view(['phone' => '+905321234567', 'attended_at' => null, 'attended_by' => null, 'id' => 1,
    'full_name' => 'x', 'authority' => null, 'region' => null, 'group_name' => null, 'ref_no' => null, 'attended_by_name' => null, 'stance' => null],
    ['id' => 0, 'role' => 'yok'])['phone'] === null);

// "Bu cihazda açık kalsın"
$_SESSION = ['uid' => $onay['id']];
$known = bin2hex(random_bytes(32)); // testte çerezi biz bilelim: kaydı bu validator'a çevir
$issue = function (int $uid) use ($known): string {
    remember_issue($uid);
    $sel = $_SESSION['rm'];
    q('UPDATE remember_tokens SET validator_hash = ? WHERE selector = ?', [hash('sha256', $known), $sel]);
    return $sel;
};
$sel = $issue($onay['id']);
$r1 = remember_consume("$sel:$known");
check('geçerli çerez giriş yapar ve yenilenir', $r1 && $r1['uid'] === $onay['id'] && is_string($r1['cookie']) && $r1['cookie'] !== "$sel:$known");
check('DB\'de sadece özet (düz validator yok)', q('SELECT validator_hash FROM remember_tokens WHERE selector = ?', [$sel])->fetchColumn()
    === hash('sha256', explode(':', $r1['cookie'])[1]));
$r2 = remember_consume("$sel:$known");
check('eski çerez kısa süre (eşzamanlı istek) kabul, yeni çerez yazılmaz', $r2 && $r2['uid'] === $onay['id'] && $r2['cookie'] === null);
q('UPDATE remember_tokens SET rotated_at = UTC_TIMESTAMP() - INTERVAL 10 MINUTE WHERE selector = ?', [$sel]);
$r3 = remember_consume("$sel:$known");
check('süre geçince eski çerez = kopya: reddedilir, cihaz kaydı silinir', $r3 === null
    && !q('SELECT COUNT(*) FROM remember_tokens WHERE selector = ?', [$sel])->fetchColumn());
check('kopya yakalanınca yeni çerez de geçersiz', remember_consume($r1['cookie']) === null);
check('bozuk / bilinmeyen çerez reddedilir', remember_consume('x:y') === null && remember_consume(str_repeat('a', 24) . ':' . str_repeat('b', 64)) === null);
$sel2 = $issue($onay['id']);
q('UPDATE users SET is_active = 0 WHERE id = ?', [$onay['id']]);
check('pasif kullanıcının çerezi geçmez', remember_consume("$sel2:$known") === null);
q('UPDATE users SET is_active = 1 WHERE id = ?', [$onay['id']]);
$sel3 = $issue($onay['id']);
q('UPDATE remember_tokens SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE selector = ?', [$sel3]);
check('süresi dolan çerez geçmez', remember_consume("$sel3:$known") === null);
remember_issue($onay['id']);
$_SESSION = ['uid' => $yon['id']]; // işlemi yapan başkası
$avBefore = (int) q('SELECT auth_version FROM users WHERE id = ?', [$onay['id']])->fetchColumn();
revoke_user_sessions($onay['id']);
check('cihazlardan çıkar: kayıtlar silinir, oturum sürümü artar', remember_device_count($onay['id']) === 0
    && (int) q('SELECT auth_version FROM users WHERE id = ?', [$onay['id']])->fetchColumn() === $avBefore + 1);
for ($i = 0; $i < REMEMBER_MAX_DEVICES + 3; $i++) {
    remember_issue($onay['id']);
}
check('kullanıcı başına en fazla ' . REMEMBER_MAX_DEVICES . ' cihaz', remember_device_count($onay['id']) === REMEMBER_MAX_DEVICES);
// Codex bulgusu: özellikten önce açılmış (sürümsüz) oturum, cihazlardan çıkarılınca da düşmeli
$_SESSION = ['uid' => $onay['id']]; // 'av' yok; onay'ın sürümü yukarıda arttı
check('sürümsüz eski oturum, sürüm artınca düşer', current_user() === null);
$_SESSION = ['uid' => $yon['id']];  // yönetici hiç çıkarılmadı (sürüm 0)
check('sürümsüz eski oturum, sürüm 0 iken açık kalır', (current_user()['id'] ?? null) === $yon['id']);
$_SESSION = [];
// Codex bulgusu: sadece "Sicil, Gsm" sütunlu dosya da telefon güncellemeli
$pr = phone_rows([['TP2', '0532 777 66 55', null], [null, '05320000000', null], ['TP1', null, '0242 111 22 33']], ['ref_no', 'phone', 'phone']);
check('telefon satırları ad sütunu olmadan', $pr === [['ref_no' => 'TP2', 'phone' => '+905327776655'], ['ref_no' => null, 'phone' => '+905320000000'],
    ['ref_no' => 'TP1', 'phone' => '+902421112233']], json_encode($pr));
$up2 = update_phones($testEvent, $pr);
check('adsız dosyayla telefon güncellenir', $up2['updated'] === 2 && $up2['not_found'] === 1, json_encode($up2));

// Oy sayacı
require APP_ROOT . '/src/votes.php';
check('onaycı oy sayamaz', !vote_add($onay, 'mavi')['ok'] && vote_add($onay, 'mavi')['status'] === 403);
$v1 = vote_add($yon, 'mavi');
$tallyOf = fn($t, $k) => (array_values(array_filter($t['options'], fn($o) => $o['key'] === $k))[0]['count'] ?? null);
check('yönetici +1 (mavi)', $v1['ok'] && $tallyOf($v1['tallies'], 'mavi') === 1 && $v1['tallies']['total'] === 1);
vote_add($yon, 'mavi');
$v3 = vote_add($yon, 'kirmizi');
check('ayrı renkler ayrı sayılır', $tallyOf($v3['tallies'], 'mavi') === 2 && $tallyOf($v3['tallies'], 'kirmizi') === 1 && $v3['tallies']['total'] === 3);
check('geçersiz seçenek 422', vote_add($yon, 'mor')['status'] === 422);
$u1 = vote_undo($yon, 'mavi');
check('belirli rengi geri al', $u1['ok'] && $u1['undone'] === 'Mavi' && $tallyOf($u1['tallies'], 'mavi') === 1);
$u2 = vote_undo($yon, null);
check('son sayımı geri al (renksiz)', $u2['ok'] && $u2['tallies']['total'] === 1);
vote_undo($yon, null);
$u4 = vote_undo($yon, null);
check('boş sayaçta geri al 409', !$u4['ok'] && $u4['status'] === 409 && $u4['tallies']['total'] === 0);
vote_add($yon, 'mavi'); vote_add($yon, 'kirmizi');
check('yönetici sıfırlayamaz (sadece admin)', !vote_reset($yon)['ok'] && vote_reset($yon)['status'] === 403);
check('oy kaydı denetimden bağımsız etkinliğe bağlı', vote_tallies($testEvent)['total'] === 2);

// Hediye dağıtım
require APP_ROOT . '/src/gifts.php';
check('hediye testleri aktif etkinlik = testEvent', (active_event()['id'] ?? null) === $testEvent);
$gAdded = import_gifts($testEvent, ['Hediye Biri', 'Hediye İki', 'Hediye Biri', '  ', 'Hediye Üç'], $yon['id']);
check('hediye yüklenir, boş/yinelenen atlanır', $gAdded === 3 && gift_stats()['total'] === 3, "added=$gAdded");
$gid = (int) q('SELECT id FROM gifts WHERE event_id = ? ORDER BY name, id LIMIT 1', [$testEvent])->fetchColumn();
$gm = mark_gift($onay, $gid, true);
check('onaycı hediye verir (gift_mark onayci\'de)', $gm['ok'] && $gm['gift']['given'] && gift_stats()['given'] === 1);
$gm2 = mark_gift($yon, $gid, true);
check('zaten verilmişi tekrar verme 409', !$gm2['ok'] && $gm2['status'] === 409 && $gm2['gift']['given']);
$gm3 = mark_gift($onay, 99999999, true);
check('olmayan hediye id 404', $gm3['status'] === 404);
$gu = mark_gift($onay, $gid, false);
check('hediye geri alınır', $gu['ok'] && !$gu['gift']['given'] && gift_stats()['given'] === 0);
$ga = add_gift($onay, 'Elle Hediye', 'A Firma');
check('elle hediye eklenir (not ile)', $ga['ok'] && $ga['gift']['name'] === 'Elle Hediye' && $ga['gift']['note'] === 'A Firma' && gift_stats()['total'] === 4);
check('boş ad 422', add_gift($yon, '   ')['status'] === 422);
mark_gift($yon, $gid, true);
$gPend = array_column(search_gifts(['status' => 'pending'], 200)['items'], 'id');
$gGiven = array_column(search_gifts(['status' => 'given'], 200)['items'], 'id');
check('hediye durum filtresi (given/pending)', in_array($gid, $gGiven, true) && !in_array($gid, $gPend, true) && count($gPend) === 3 && count($gGiven) === 1);
clear_gifts($testEvent);
check('clear_gifts siler', gift_stats()['total'] === 0);
q('DELETE FROM import_batches WHERE event_id = ?', [$testEvent]); // import_gifts'in açtığı batch satırları (shutdown events siler, FK için)

check('denetim kaydı yazılır', (function () { audit('export', '__test__'); return (bool) q("SELECT COUNT(*) FROM audit_log WHERE target = '__test__'")->fetchColumn(); })());
q("DELETE FROM audit_log WHERE target = '__test__'");

echo $fail ? "\n$fail test BAŞARISIZ\n" : "\nTüm testler geçti\n";
exit($fail ? 1 : 0);
