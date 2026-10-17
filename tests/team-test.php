<?php
declare(strict_types=1);

// Takım ("kimin adamı") testi: en fazla 2 kişi, 3. ret, çıkarınca yer açılır, yetki, filtre/çip sayıları,
// pasife alınca üyelik silinir, yeni etkinlikte takım boş, PARALEL "son yer" yarışında tek kazanan.
// Kendi geçici listesini ve kullanıcılarını açar, bitince siler. İşaretli listede --force olmadan çalışmaz.
//   php tests/team-test.php
if (PHP_SAPI !== 'cli') {
    exit;
}
require __DIR__ . '/../src/bootstrap.php';

// --- işçi modu: php team-test.php --worker <userId> <personId> <start>
if (($argv[1] ?? '') === '--worker') {
    $u = q('SELECT id, username, full_name, role, region, is_active FROM users WHERE id = ?', [(int) $argv[2]])->fetch();
    $u['id'] = (int) $u['id'];
    active_event(true);
    db();
    while (microtime(true) < (float) $argv[4]) {
        usleep(200);
    }
    echo team_add($u, (int) $argv[3])['status'];
    exit;
}

$prev = active_event();
if ($prev && !in_array('--force', $argv, true)
    && (int) q('SELECT COUNT(*) FROM people WHERE event_id = ? AND attended_at IS NOT NULL', [$prev['id']])->fetchColumn() > 0) {
    fwrite(STDERR, "Aktif listede işaretleme var; test birkaç saniye başka liste açar. Etkinlik bittiyse --force.\n");
    exit(2);
}

$fail = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $fail;
    echo ($ok ? '  OK   ' : '  FAIL ') . $name . ($ok || $detail === '' ? '' : " — $detail") . "\n";
    if (!$ok) $fail++;
}

$tag = 'zzteam_' . bin2hex(random_bytes(3));
$users = [];
foreach ([['a', 'onayci', 'Merkez'], ['b', 'onayci', 'Kemer'], ['c', 'onayci', null], ['y', 'yonetici', null]] as [$k, $role, $region]) {
    q('INSERT INTO users (username, password_hash, full_name, role, region, created_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())',
        ["{$tag}_$k", password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT), "Takım " . strtoupper($k), $role, $region]);
    $users[$k] = ['id' => (int) db()->lastInsertId(), 'username' => "{$tag}_$k", 'full_name' => 'Takım ' . strtoupper($k),
                  'role' => $role, 'region' => $region, 'is_active' => 1];
}
$events = [];
register_shutdown_function(function () use (&$events, $users, $prev) {
    foreach ($events as $ev) {
        q('DELETE FROM team_members WHERE event_id = ?', [$ev]);
        q('DELETE FROM people WHERE event_id = ?', [$ev]);
        q('DELETE FROM events WHERE id = ?', [$ev]);
    }
    $ids = array_column($users, 'id');
    $in = implode(',', array_fill(0, count($ids), '?'));
    q("DELETE FROM team_members WHERE user_id IN ($in)", $ids);
    q("DELETE FROM audit_log WHERE user_id IN ($in)", $ids);
    q("DELETE FROM users WHERE id IN ($in)", $ids);
    if ($prev) {
        activate_event($prev['id']);
    }
    echo "(geçici test verisi silindi)\n";
});

[$a, $b, $c, $y] = [$users['a'], $users['b'], $users['c'], $users['y']];
$events[] = $event = create_event("__TEST__ $tag", $y['id']);
active_event(true);
// Firma iki bölgede şube: a (Merkez) ve b (Kemer) aynı firmanın FARKLI satırlarını görür
$f1m = insert_person($event, null, ['full_name' => 'TAKIM GIDA A.Ş.', 'authority' => 'Ali Takım', 'region' => 'Merkez', 'ref_no' => 'T1']);
$f1k = insert_person($event, null, ['full_name' => 'TAKIM GIDA A.Ş. KEMER ŞB.', 'authority' => 'Ali Takım', 'region' => 'Kemer', 'ref_no' => 'T2']);
$f2 = insert_person($event, null, ['full_name' => 'BAŞKA LTD.', 'authority' => 'Veli Başka', 'region' => 'Merkez', 'ref_no' => 'T3']);
$f3 = insert_person($event, null, ['full_name' => 'YARIŞ LTD.', 'authority' => 'Can Yarış', 'region' => null, 'ref_no' => 'T4']);
check('aynı firma tek firm_key', q('SELECT COUNT(DISTINCT firm_key) FROM people WHERE id IN (?, ?)', [$f1m, $f1k])->fetchColumn() == 1);

// 1) limit 2
$r = team_add($a, $f1m);
check('a takıma alır', $r['ok'] && $r['person']['team']['mine'] && count($r['person']['team']['members']) === 1, json_encode($r, JSON_UNESCAPED_UNICODE));
check('a tekrar alamaz (409)', team_add($a, $f1m)['status'] === 409);
$r = team_add($b, $f1k); // farklı bölge, aynı firma
check('b (başka bölge, aynı firma) alır → 2/2', $r['ok'] && $r['person']['team']['full']);
$r = team_add($c, $f1m);
check('c 3. kişi alamaz (409)', $r['status'] === 409 && str_contains($r['error'], 'Takım A') && str_contains($r['error'], 'Takım B'), $r['error'] ?? '');
check('DB: firmada 2 kayıt', q('SELECT COUNT(*) FROM team_members WHERE event_id = ?', [$event])->fetchColumn() == 2);

// 2) yetki: onaycı başkasınınkini çıkaramaz, yönetici çıkarır
check('c, a\'nınkini çıkaramaz (403)', team_remove($c, $f1m, $a['id'])['status'] === 403);
check('c takımda olmadığı kayıt (409)', team_remove($c, $f1m)['status'] === 409);
$r = team_remove($y, $f1m, $b['id']);
check('yönetici b\'yi çıkarır', $r['ok'] && count($r['person']['team']['members']) === 1);
check('yer açılınca c alır', team_add($c, $f1m)['ok']);
check('a kendi kaydını çıkarır', team_remove($a, $f1m)['ok']);

// 3) filtre + çip sayıları (kapsam içinde, firma bazında)
team_add($c, $f2);
$mine = search_people($c, ['owner' => (string) $c['id']]);
check('c: Benimkiler = 2 firma', $mine['total'] === 2, (string) $mine['total']);
$none = search_people($c, ['owner' => NONE_VALUE]);
check('c: Sahipsiz = 1 firma', $none['total'] === 1 && $none['items'][0]['id'] === $f3, (string) $none['total']);
$bd = team_breakdown($a); // a sadece Merkez + bölgesiz görür
$byVal = array_column($bd, 'total', 'value');
check('a çipleri: Benimkiler ilk, 0', $bd[0]['label'] === 'Benimkiler' && $bd[0]['total'] === 0);
check('a çipleri: C = 2, Sahipsiz = 1', ($byVal[(string) $c['id']] ?? -1) === 2 && ($byVal[NONE_VALUE] ?? -1) === 1, json_encode($byVal));
check('facet_totals owner = team_breakdown', facet_totals($a)['owner'] == $byVal);
check('geçersiz owner hiçbir şey dönmez', search_people($c, ['owner' => 'abc'])['total'] === 0);

// 4) pasife alınca üyelik silinir, yer açılır
team_add($a, $f1m); // f1: c + a = dolu
q('UPDATE users SET is_active = 0 WHERE id = ?', [$c['id']]);
check('pasif c sayılmaz: b alabilir', team_add($b, $f1k)['ok']);
check('team_clear_user c\'nin 2 kaydını siler', team_clear_user($c['id']) === 2);
check('pasif kullanıcı (eski oturum) ekleyemez (403)', team_add($c, $f3)['status'] === 403);
q('UPDATE users SET is_active = 1 WHERE id = ?', [$c['id']]);
check('c yeniden aktif: takımı geri gelmedi, firma 2/2', !team_add($c, $f1m)['ok']);

// 5) PARALEL son yer: f3'te a var, b ve c aynı anda son yeri ister → tek kazanan, en fazla 2
$php = escapeshellarg(PHP_BINARY);
$self = escapeshellarg(__FILE__);
$bad = 0;
for ($round = 1; $round <= 15; $round++) {
    q('DELETE FROM team_members WHERE event_id = ? AND firm_key = (SELECT firm_key FROM people WHERE id = ?)', [$event, $f3]);
    team_add($a, $f3);
    $start = sprintf('%.4f', microtime(true) + 0.6);
    $procs = $out = [];
    foreach (['b' => $b, 'c' => $c] as $k => $u) {
        $procs[$k] = proc_open("$php $self --worker {$u['id']} $f3 $start", [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$k]);
    }
    foreach ($procs as $k => $p) {
        $out[$k] = trim(stream_get_contents($pipes[$k][1]));
        proc_close($p);
    }
    $n = (int) q('SELECT COUNT(*) FROM team_members WHERE event_id = ? AND firm_key = (SELECT firm_key FROM people WHERE id = ?)', [$event, $f3])->fetchColumn();
    sort($out);
    if ($n !== 2 || $out !== ['200', '409']) {
        $bad++;
        echo "  tur $round: $n kayıt, yanıtlar " . implode('/', $out) . "\n";
    }
}
check('paralel son yer: 15 turda daima tek kazanan, 2 kayıt', $bad === 0);

// 6) yeni etkinlik: takım boş başlar
$events[] = $event2 = create_event("__TEST2__ $tag", $y['id']);
active_event(true);
$g = insert_person($event2, null, ['full_name' => 'TAKIM GIDA A.Ş.', 'authority' => 'Ali Takım', 'region' => 'Merkez', 'ref_no' => 'T1']);
$v = group_view(find_group($a, $g), $a);
check('yeni etkinlikte aynı firma sahipsiz', $v['team']['members'] === []);

echo $fail ? "\nBAŞARISIZ: $fail kontrol\n" : "\nTüm takım kontrolleri geçti\n";
exit($fail ? 1 : 0);
