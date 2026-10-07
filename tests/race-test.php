<?php
declare(strict_types=1);

// Eşzamanlılık testi: aynı firma grubuna AYNI ANDA "GELDİ" ve "Geri al" gelince grup yarım
// kalmamalı (hepsi işaretli ya da hiçbiri). İki ayrı PHP süreci gerçekten paralel çalışır.
// Kendi geçici listesini açıp siler; işaretli bir listede --force olmadan çalışmaz.
//   php tests/race-test.php
if (PHP_SAPI !== 'cli') {
    exit;
}
require __DIR__ . '/../src/bootstrap.php';

// --- işçi modu: php race-test.php --worker mark|unmark <userId> <personId>
if (($argv[1] ?? '') === '--worker') {
    $u = q('SELECT id, username, full_name, role, region, is_active FROM users WHERE id = ?', [(int) $argv[3]])->fetch();
    $u['id'] = (int) $u['id'];
    active_event(true);
    db(); // bağlantı hazır olsun
    // İki süreç aynı ana kadar bekler: yarış gerçekten aynı milisaniyede başlar
    while (microtime(true) < (float) $argv[5]) {
        usleep(200);
    }
    $r = $argv[2] === 'mark' ? mark_person($u, (int) $argv[4]) : unmark_person($u, (int) $argv[4]);
    echo $r['status'];
    exit;
}

$prev = active_event();
if ($prev && !in_array('--force', $argv, true)
    && (int) q('SELECT COUNT(*) FROM people WHERE event_id = ? AND attended_at IS NOT NULL', [$prev['id']])->fetchColumn() > 0) {
    fwrite(STDERR, "Aktif listede işaretleme var; test birkaç saniye başka liste açar. Etkinlik bittiyse --force.\n");
    exit(2);
}
$tag = 'zzrace_' . bin2hex(random_bytes(3));
q('INSERT INTO users (username, password_hash, full_name, role, region, created_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())',
    ["{$tag}_onay", password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT), 'Yarış Onaycı', 'onayci', 'Merkez']);
$uid = (int) db()->lastInsertId();
$event = create_event("__TEST__ $tag", $uid);
register_shutdown_function(function () use ($event, $uid, $prev) {
    q('DELETE FROM attendance_log WHERE event_id = ?', [$event]);
    q('DELETE FROM people WHERE event_id = ?', [$event]);
    q('DELETE FROM events WHERE id = ?', [$event]);
    q('DELETE FROM users WHERE id = ?', [$uid]);
    if ($prev) {
        activate_event($prev['id']);
    }
    echo "(geçici test verisi silindi)\n";
});
$ids = [];
foreach (range(1, 4) as $i) {
    $ids[] = insert_person($event, null, ['full_name' => "YARIŞ MAĞAZACILIK A.Ş. ŞUBE $i", 'authority' => 'Yarış Yetkili', 'region' => 'Merkez', 'ref_no' => "R$i"]);
}

$php = escapeshellarg(PHP_BINARY);
$self = escapeshellarg(__FILE__);
$half = 0;
$rounds = 30;
for ($round = 1; $round <= $rounds; $round++) {
    // Kısmi durum: 4 şubeden 2'si bu onaycı tarafından az önce işaretli
    q('UPDATE people SET attended_at = NULL, attended_by = NULL WHERE event_id = ?', [$event]);
    q('UPDATE people SET attended_at = UTC_TIMESTAMP(), attended_by = ? WHERE id IN (?, ?)', [$uid, $ids[0], $ids[1]]);
    $procs = [];
    $start = sprintf('%.4f', microtime(true) + 0.6); // süreçler açılıp bağlansın, sonra aynı anda
    foreach (['mark' => $ids[2], 'unmark' => $ids[0]] as $action => $pid) {
        $procs[] = proc_open("$php $self --worker $action $uid $pid $start", [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$action]);
    }
    foreach ($procs as $i => $p) {
        proc_close($p);
    }
    $came = (int) q('SELECT COUNT(*) FROM people WHERE event_id = ? AND attended_at IS NOT NULL', [$event])->fetchColumn();
    if ($came !== 0 && $came !== 4) {
        $half++;
        echo "  tur $round: YARIM ($came/4)\n";
    }
}
echo $half ? "BAŞARISIZ: $half/$rounds turda grup yarım kaldı\n" : "Tüm $rounds turda grup tutarlı (hepsi ya da hiçbiri)\n";
exit($half ? 1 : 0);
