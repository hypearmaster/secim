<?php
declare(strict_types=1);

/*
 * "Bizimkiler": bizimle olan kişilerin isim listesi, aktif listedeki üyelerle eşleştirilir.
 *  - Tam eşleşme (yetkili adı ya da unvan, Türkçe karaktersiz)  -> matched, kişiler "Bizimle" olur
 *  - Benzer (ad+soyad bir harf farkla / fazladan ikinci ad)     -> suggested, admin onaylar
 *  - Yok                                                         -> none, admin elle bağlar
 * Bir isim birden çok firmanın yetkilisi olabilir: hepsi bağlanır.
 */

/** Firmasız (bağımsız) kaydedilen kişilerin grubu. */
const INDEPENDENT_GROUP = 'Bağımsız';

/** İsim listesi dosyasından isimleri çıkarır (başlığa göre ad sütunu, yoksa en çok yazı olan sütun). */
function read_name_list(string $path, string $ext): array
{
    $sheet = read_sheet($path, $ext);
    $col = null;
    foreach ($sheet['header'] as $i => $h) {
        if (in_array(guess_field($h), ['full_name', 'authority'], true)) {
            $col = $i;
            break;
        }
    }
    if ($col === null) { // başlık tanınmadı: sayı olmayan en dolu sütun
        $best = -1;
        foreach (array_keys($sheet['header']) as $i) {
            $n = count(array_filter($sheet['rows'], fn($r) => ($r[$i] ?? null) !== null && !is_numeric($r[$i])));
            if ($n > $best) {
                [$best, $col] = [$n, $i];
            }
        }
    }
    $names = [];
    foreach ($sheet['rows'] as $r) {
        $n = clean_text($r[$col] ?? null, 160);
        if ($n !== null && !is_numeric($n) && !isset($names[search_key($n)])) {
            $names[search_key($n)] = $n; // dosya içi tekrar bir kez
        }
    }
    return array_values($names);
}

/** İki kelime "aynı" mı: eşit ya da (uzun kelimede) tek harf farkı. */
function similar_word(string $a, string $b): bool
{
    if ($a === $b) {
        return true;
    }
    return min(strlen($a), strlen($b)) >= 5 && levenshtein($a, $b) <= 1;
}

/**
 * Bir ismin aday kişileri.
 * @param array<string, list<int>> $index search_key => kişi id'leri
 * @return array{0:'matched'|'suggested'|'none', 1:list<int>}
 */
function match_name(string $name, array $index): array
{
    $k = search_key($name);
    if (isset($index[$k])) {
        return ['matched', array_values(array_unique($index[$k]))];
    }
    $w = explode(' ', $k);
    if (count($w) < 2) {
        return ['none', []];
    }
    $hits = [];
    foreach ($index as $key => $ids) {
        // Firma sahibinin adını taşıyor: "AHMET YILDIRIM İNŞAAT LTD ŞTİ" içinde "ahmet yildirim"
        if (str_contains(" $key ", " $k ")) {
            array_push($hits, ...$ids);
            continue;
        }
        $v = explode(' ', $key);
        if (count($v) < 2) {
            continue;
        }
        // Soyad (son kelime) ve ilk ad benzer olmalı: "Ahmet Cemil Sönmez" ~ "Ahmet Sönmez",
        // "Abdullah Boynikara" ~ "Abdullah Boynukara". Sadece soyad tutması yetmez.
        if (similar_word(end($w), end($v)) && similar_word($w[0], $v[0])) {
            array_push($hits, ...$ids);
        }
    }
    $hits = array_values(array_unique($hits));
    return $hits ? ['suggested', $hits] : ['none', []];
}

/** Aktif listedeki kişilerden arama indeksi: her yetkili adı ve unvan ayrı anahtar. */
function supporter_index(int $eventId): array
{
    $index = [];
    foreach (q('SELECT id, full_name, authority FROM people WHERE event_id = ?', [$eventId])->fetchAll() as $p) {
        $keys = [search_key($p['full_name'])];
        foreach (explode(',', (string) $p['authority']) as $a) {
            $keys[] = search_key($a);
        }
        foreach (array_unique(array_filter($keys)) as $key) {
            $index[$key][] = (int) $p['id'];
        }
    }
    return $index;
}

/** Bağlı kişilerin stance'ini "Bizimle" yapar (sadece onaylı eşleşmede çağrılır). */
function apply_supporter_stance(array $personIds): void
{
    $stance = supporter_stance();
    if ($stance === null || !$personIds) {
        return;
    }
    $in = implode(',', array_fill(0, count($personIds), '?'));
    q("UPDATE people SET stance = ? WHERE id IN ($in)", [$stance, ...$personIds]);
}

/** Artık hiçbir onaylı Bizimkiler kaydına bağlı olmayan kişilerin "Bizimle" işaretini kaldırır. */
function release_supporter_stance(array $personIds): void
{
    $stance = supporter_stance();
    foreach (array_unique($personIds) as $pid) {
        $still = (int) q("SELECT COUNT(*) FROM supporter_people sp JOIN supporters s ON s.id = sp.supporter_id
                          WHERE sp.person_id = ? AND s.status = 'matched'", [$pid])->fetchColumn();
        if (!$still && $stance !== null) {
            q('UPDATE people SET stance = NULL WHERE id = ? AND stance = ?', [$pid, $stance]);
        }
    }
}

/**
 * Listeyi aktif etkinliğe yükler; aynı etkinliğin eski Bizimkiler listesinin yerini alır.
 * @return array{matched:int, suggested:int, none:int}
 */
function import_supporters(int $eventId, array $names, int $userId): array
{
    $index = supporter_index($eventId);
    $stats = ['matched' => 0, 'suggested' => 0, 'none' => 0];
    $pdo = db();
    $pdo->beginTransaction();
    try {
        clear_supporters($eventId);
        foreach ($names as $name) {
            [$status, $ids] = match_name($name, $index);
            q('INSERT INTO supporters (event_id, name, search_key, status, created_by, created_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())',
                [$eventId, $name, search_key($name), $status, $userId]);
            $sid = (int) $pdo->lastInsertId();
            foreach ($ids as $pid) {
                q('INSERT INTO supporter_people (supporter_id, person_id) VALUES (?, ?)', [$sid, $pid]);
            }
            if ($status === 'matched') {
                apply_supporter_stance($ids);
            }
            $stats[$status]++;
        }
        // Elle "Bizimle" işaretlenmiş ve şimdi listeyle eşleşen gruplar: elle kaydı fazlalık, sil
        // (MySQL silinen tabloyu alt sorguda okutmaz: önce bul, sonra sil)
        $dupes = q("SELECT s.id FROM supporters s WHERE s.event_id = ? AND s.source = 'manual'
                    AND NOT EXISTS (SELECT 1 FROM supporter_people sp WHERE sp.supporter_id = s.id
                        AND NOT EXISTS (SELECT 1 FROM supporter_people sp2 JOIN supporters l ON l.id = sp2.supporter_id
                                        WHERE sp2.person_id = sp.person_id AND l.source = 'list' AND l.status = 'matched'))",
            [$eventId])->fetchAll(PDO::FETCH_COLUMN);
        foreach ($dupes as $sid) {
            q('DELETE FROM supporters WHERE id = ?', [$sid]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $stats;
}

/**
 * Yüklenen isim listesini siler (elle "Bizimle" işaretlenenler KALIR); listenin verdiği
 * "Bizimle" işaretlerini geri alır. Transaction içinde çağrılabilir.
 */
function clear_supporters(int $eventId): void
{
    $pids = q("SELECT sp.person_id FROM supporter_people sp JOIN supporters s ON s.id = sp.supporter_id
               WHERE s.event_id = ? AND s.status = 'matched' AND s.source = 'list'", [$eventId])->fetchAll(PDO::FETCH_COLUMN);
    $sids = q("SELECT id FROM supporters WHERE event_id = ? AND source = 'list'", [$eventId])->fetchAll(PDO::FETCH_COLUMN);
    foreach ($sids as $sid) {
        delete_independent_people((int) $sid);
    }
    q("DELETE FROM supporters WHERE event_id = ? AND source = 'list'", [$eventId]); // supporter_people CASCADE
    release_supporter_stance(array_map('intval', $pids));
}

/** Bu kayda bağlı bağımsız kişileri (firmasız, Bizimkiler'den eklenen) siler; GELDİ kaydı olan kalır. */
function delete_independent_people(int $supporterId): int
{
    $kept = 0;
    $rows = q("SELECT p.id FROM supporter_people sp JOIN people p ON p.id = sp.person_id
               WHERE sp.supporter_id = ? AND p.batch_id IS NULL AND p.group_name = ?", [$supporterId, INDEPENDENT_GROUP])->fetchAll(PDO::FETCH_COLUMN);
    foreach ($rows as $pid) {
        if ((int) q('SELECT COUNT(*) FROM attendance_log WHERE person_id = ?', [$pid])->fetchColumn()) {
            $kept++; // işlem kaydı var: silinmez (denetim izi bozulmasın)
            continue;
        }
        q('DELETE FROM supporter_people WHERE person_id = ?', [$pid]);
        q('DELETE FROM people WHERE id = ?', [$pid]);
    }
    return $kept;
}

/** Tek kaydı listeden siler; verdiği "Bizimle" işaretini geri alır. @return silinemeyen bağımsız kişi sayısı */
function delete_supporter(array $sup): int
{
    $kept = delete_independent_people((int) $sup['id']);
    $pids = supporter_person_ids((int) $sup['id']);
    q('DELETE FROM supporters WHERE id = ?', [$sup['id']]);
    release_supporter_stance($pids);
    return $kept;
}

/**
 * Firmaya bağlı olmayan ismi, aktif listeye "Bağımsız" grubunda ayrı kişi olarak ekler ve bağlar.
 * Onay ekranında GELDİ işaretlenebilir, Bizimkiler'de sayılır. Aynı isimde bağımsız kayıt varsa onu kullanır.
 */
function make_independent(array $sup): int
{
    if (($sup['status'] ?? '') !== 'none') {
        throw new InvalidArgumentException('Bağımsız kayıt sadece bulunamayan isimler için.');
    }
    $pid = q('SELECT id FROM people WHERE event_id = ? AND batch_id IS NULL AND group_name = ? AND search_key = ?',
        [$sup['event_id'], INDEPENDENT_GROUP, search_key($sup['name'])])->fetchColumn();
    if (!$pid) {
        $pid = insert_person((int) $sup['event_id'], null, [
            'full_name'  => $sup['name'],
            'group_name' => INDEPENDENT_GROUP,
            'stance'     => supporter_stance(),
            'extra'      => ['kaynak' => 'Bizimkiler — firmasız'],
        ]);
    }
    q('INSERT IGNORE INTO supporter_people (supporter_id, person_id) VALUES (?, ?)', [$sup['id'], $pid]);
    q("UPDATE supporters SET status = 'matched' WHERE id = ?", [$sup['id']]);
    apply_supporter_stance([(int) $pid]);
    return (int) $pid;
}

/** Kayıt bu etkinliğe mi ait (başka etkinliğin id'siyle oynanmasın)? */
function find_supporter(int $eventId, int $id): ?array
{
    $s = q('SELECT * FROM supporters WHERE id = ? AND event_id = ?', [$id, $eventId])->fetch();
    return $s ?: null;
}

function supporter_person_ids(int $supporterId): array
{
    return array_map('intval', q('SELECT person_id FROM supporter_people WHERE supporter_id = ?', [$supporterId])->fetchAll(PDO::FETCH_COLUMN));
}

/** Tahmini onayla: bağlı adayların hepsi "Bizimle" olur. */
function confirm_supporter(array $sup): void
{
    q("UPDATE supporters SET status = 'matched' WHERE id = ?", [$sup['id']]);
    apply_supporter_stance(supporter_person_ids((int) $sup['id']));
}

/** Kişi bağla (elle seçim): seçilen kaydın firma grubunun (şubeler) TÜMÜ bağlanır. Aynı etkinlikte olmalı. */
function link_supporter(array $sup, int $personId): bool
{
    $key = q('SELECT firm_key FROM people WHERE id = ? AND event_id = ?', [$personId, $sup['event_id']])->fetchColumn();
    if ($key === false) {
        return false;
    }
    $ids = q('SELECT id FROM people WHERE event_id = ? AND firm_key = ?', [$sup['event_id'], $key])->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) {
        q('INSERT IGNORE INTO supporter_people (supporter_id, person_id) VALUES (?, ?)', [$sup['id'], $id]);
    }
    q("UPDATE supporters SET status = 'matched' WHERE id = ?", [$sup['id']]);
    apply_supporter_stance(supporter_person_ids((int) $sup['id']));
    return true;
}

/** Bir firmanın bağını kaldır (şubeleri dahil; bağlama da firma bazında). Hiç bağ kalmazsa "bulunamadı". */
function unlink_supporter(array $sup, int $personId): void
{
    $key = q('SELECT firm_key FROM people WHERE id = ? AND event_id = ?', [$personId, $sup['event_id']])->fetchColumn();
    $pids = $key === false ? [$personId]
        : array_map('intval', q('SELECT id FROM people WHERE event_id = ? AND firm_key = ?', [$sup['event_id'], $key])->fetchAll(PDO::FETCH_COLUMN));
    $in = implode(',', array_fill(0, count($pids), '?'));
    q("DELETE FROM supporter_people WHERE supporter_id = ? AND person_id IN ($in)", [$sup['id'], ...$pids]);
    if (!supporter_person_ids((int) $sup['id'])) {
        // elle açılmış kaydın bağı kalmadı: kayıt anlamsız, sil; listedeki isim "Bulunamadı"ya döner
        q("DELETE FROM supporters WHERE id = ? AND source = 'manual'", [$sup['id']]);
        q("UPDATE supporters SET status = 'none' WHERE id = ? AND source = 'list'", [$sup['id']]);
    }
    release_supporter_stance($pids);
}

/** Tahmini reddet: tüm adaylar kalkar, "bulunamadı" olur. */
function reject_supporter(array $sup): void
{
    $pids = supporter_person_ids((int) $sup['id']);
    q('DELETE FROM supporter_people WHERE supporter_id = ?', [$sup['id']]);
    q("UPDATE supporters SET status = 'none' WHERE id = ?", [$sup['id']]);
    release_supporter_stance($pids);
}

/**
 * Bağlı kişileri FİRMA bazında toplar (onay ekranındaki gibi: aynı firmanın şubeleri tek satır).
 * "Erdal SAĞLAM → 15 FLO şubesi" 15 satır değil, tek firma "· 15 şube" olur.
 * @return list<array{firm_key:string,person_id:int,person_ids:list<int>,name:string,full_name:string,
 *                    has_authority:bool,branch_count:int,region:?string,ref_no:?string,
 *                    group_name:?string,batch_id:?int,attended_at:?string}>
 */
function group_supporter_people(array $rows): array
{
    $byFirm = [];
    foreach ($rows as $p) {
        $byFirm[$p['firm_key']][] = $p;
    }
    $firms = [];
    foreach ($byFirm as $key => $branches) {
        $first = $branches[0];
        $came = array_values(array_filter($branches, fn($b) => $b['attended_at'] !== null));
        $firms[] = [
            'firm_key'      => (string) $key,
            'person_id'     => (int) $first['id'],
            'person_ids'    => array_map(fn($b) => (int) $b['id'], $branches),
            'name'          => $first['authority'] ?: $first['full_name'],
            'full_name'     => $first['full_name'],
            'has_authority' => (bool) $first['authority'],
            'branch_count'  => count($branches),
            'region'        => $first['region'],
            'ref_no'        => $first['ref_no'],
            'group_name'    => $first['group_name'],
            'batch_id'      => $first['batch_id'],
            'attended_at'   => $came ? $came[count($came) - 1]['attended_at'] : null,
        ];
    }
    return $firms;
}

/**
 * Liste ekranı için kayıtlar + bağlı FİRMALAR (geldi bilgisiyle).
 * @return list<array{id:int,name:string,status:string,firms:list<array>,came:bool}>
 */
function list_supporters(int $eventId, string $filter = ''): array
{
    $rows = q('SELECT id, name, status, source FROM supporters WHERE event_id = ? ORDER BY name', [$eventId])->fetchAll();
    $people = [];
    foreach (q("SELECT sp.supporter_id, p.id, p.firm_key, p.full_name, p.authority, p.region, p.ref_no, p.group_name, p.batch_id, p.attended_at, u.full_name AS attended_by_name
                FROM supporter_people sp JOIN supporters s ON s.id = sp.supporter_id
                JOIN people p ON p.id = sp.person_id LEFT JOIN users u ON u.id = p.attended_by
                WHERE s.event_id = ? ORDER BY p.full_name", [$eventId])->fetchAll() as $p) {
        $people[(int) $p['supporter_id']][] = $p;
    }
    $out = [];
    foreach ($rows as $r) {
        $r['id'] = (int) $r['id'];
        $r['firms'] = group_supporter_people($people[$r['id']] ?? []);
        $came = $r['status'] === 'matched' && array_filter($r['firms'], fn($f) => $f['attended_at'] !== null);
        $r['came'] = (bool) $came;
        $keep = match ($filter) {
            'came'    => $r['came'],
            'pending' => $r['status'] === 'matched' && !$r['came'],
            'review'  => $r['status'] !== 'matched',
            default   => true,
        };
        if ($keep) {
            $out[] = $r;
        }
    }
    return $out;
}

/**
 * @return array{total:int, matched:int, suggested:int, none:int, came:int, matched_firms:int, came_firms:int}
 * matched/came = İSİM sayısı; matched_firms/came_firms = FİRMA sayısı (şubeler tek) — panelle tutarlı.
 */
function supporter_stats(int $eventId): array
{
    $s = ['total' => 0, 'matched' => 0, 'suggested' => 0, 'none' => 0, 'came' => 0, 'matched_firms' => 0, 'came_firms' => 0];
    foreach (q('SELECT status, COUNT(*) n FROM supporters WHERE event_id = ? GROUP BY status', [$eventId])->fetchAll() as $r) {
        $s[$r['status']] = (int) $r['n'];
        $s['total'] += (int) $r['n'];
    }
    $s['came'] = (int) q("SELECT COUNT(DISTINCT s.id) FROM supporters s JOIN supporter_people sp ON sp.supporter_id = s.id
                          JOIN people p ON p.id = sp.person_id
                          WHERE s.event_id = ? AND s.status = 'matched' AND p.attended_at IS NOT NULL", [$eventId])->fetchColumn();
    $s['matched_firms'] = (int) q("SELECT COUNT(DISTINCT p.firm_key) FROM supporters s JOIN supporter_people sp ON sp.supporter_id = s.id
                                   JOIN people p ON p.id = sp.person_id WHERE s.event_id = ? AND s.status = 'matched'", [$eventId])->fetchColumn();
    $s['came_firms'] = (int) q("SELECT COUNT(DISTINCT p.firm_key) FROM supporters s JOIN supporter_people sp ON sp.supporter_id = s.id
                                JOIN people p ON p.id = sp.person_id
                                WHERE s.event_id = ? AND s.status = 'matched' AND p.attended_at IS NOT NULL", [$eventId])->fetchColumn();
    return $s;
}
