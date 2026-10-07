<?php
declare(strict_types=1);

// Çekirdek davranış testi (kapsam, işaretleme, geri alma, arama). Demo veriyle çalışır.
//   php bin/seed-demo.php && php tests/core-test.php
if (PHP_SAPI !== 'cli') {
    exit;
}
require __DIR__ . '/../src/bootstrap.php';

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

$onay = user_by('onay1');        // onayci, bölge Merkez
$yon = user_by('yonetici1');     // yonetici, tüm bölgeler
$ev = active_event();
check('aktif etkinlik var', $ev !== null);
q('UPDATE people SET attended_at = NULL, attended_by = NULL WHERE event_id = ?', [$ev['id']]);

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

echo $fail ? "\n$fail test BAŞARISIZ\n" : "\nTüm testler geçti\n";
exit($fail ? 1 : 0);
