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
    q('DELETE FROM attendance_log WHERE event_id = ?', [$testEvent]);
    q('DELETE FROM people WHERE event_id = ?', [$testEvent]);
    q('DELETE FROM events WHERE id = ?', [$testEvent]);
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

echo $fail ? "\n$fail test BAŞARISIZ\n" : "\nTüm testler geçti\n";
exit($fail ? 1 : 0);
