<?php
declare(strict_types=1);

/*
 * Hediye dağıtım takibi: üye listesinden BAĞIMSIZ, sadece "ad" (+ opsiyonel not) içeren bir liste.
 * Sahada hediye verilince "VERDİK" işaretlenir. Bölge, aynı etkinliğin kişi kayıtlarından
 * kesin isim eşleşmesiyle alınır. Herkes tüm hediye listesini görür/işaretler.
 */

const GIFT_SELECT = 'SELECT g.id, g.name, g.note, g.region, g.given_at, g.given_by, u.full_name AS given_by_name
    FROM gifts g LEFT JOIN users u ON u.id = g.given_by';

/** Tam isim/unvan ve onaylı Bizimkiler bağlantıları; farklı bölgeler belirsiz kalır. */
function gift_region_index(int $eventId): array
{
    $rows = q('SELECT full_name AS name, authority, region FROM people WHERE event_id = ?', [$eventId])->fetchAll();
    $linked = q("SELECT s.name, NULL AS authority, p.region FROM supporters s
        JOIN supporter_people sp ON sp.supporter_id = s.id JOIN people p ON p.id = sp.person_id
        WHERE s.event_id = ? AND p.event_id = ? AND s.status = 'matched'", [$eventId, $eventId])->fetchAll();
    $index = [];
    foreach (array_merge($rows, $linked) as $row) {
        foreach (array_merge([$row['name']], explode(',', (string) $row['authority'])) as $name) {
            $key = search_key($name);
            if ($key !== '') $index[$key][] = $row['region'];
        }
    }
    return $index;
}

function gift_matched_region(string $name, array $index): ?string
{
    $regions = array_unique($index[search_key($name)] ?? []);
    return count($regions) === 1 ? (array_values($regions)[0] ?: null) : null;
}

/** Elle seçim için yalnızca bu etkinliğin onay listesindeki bölgeler. */
function gift_regions(int $eventId): array
{
    return q("SELECT DISTINCT region FROM people WHERE event_id = ? AND region IS NOT NULL AND region <> '' ORDER BY region",
        [$eventId])->fetchAll(PDO::FETCH_COLUMN);
}

/** Mevcut işaretleri ve elle seçilmiş bölgeleri koruyarak belirsizleri eşleştir. */
function sync_gift_regions(int $eventId): int
{
    $index = gift_region_index($eventId);
    $updated = 0;
    foreach (q('SELECT id, name FROM gifts WHERE event_id = ? AND region IS NULL', [$eventId])->fetchAll() as $gift) {
        $region = gift_matched_region($gift['name'], $index);
        if ($region !== null) {
            $updated += q('UPDATE gifts SET region = ? WHERE id = ? AND event_id = ? AND region IS NULL',
                [$region, $gift['id'], $eventId])->rowCount();
        }
    }
    return $updated;
}

function set_gift_region(array $user, int $id, string $region, int $expectedEventId): array
{
    if (!can('gift_mark', $user)) return ['ok' => false, 'status' => 403, 'error' => 'Yetkiniz yok.'];
    $event = active_event();
    if (!$event || $event['id'] !== $expectedEventId) {
        return ['ok' => false, 'status' => 409, 'error' => 'Aktif liste değişti, sayfayı yenileyin.'];
    }
    if (!in_array($region, gift_regions($event['id']), true)) {
        return ['ok' => false, 'status' => 422, 'error' => 'Onay listesinden bir bölge seçin.'];
    }
    $row = q(GIFT_SELECT . ' WHERE g.id = ? AND g.event_id = ?', [$id, $event['id']])->fetch();
    if (!$row) return ['ok' => false, 'status' => 404, 'error' => 'Hediye kaydı bulunamadı.'];
    q('UPDATE gifts SET region = ? WHERE id = ? AND event_id = ?', [$region, $id, $event['id']]);
    audit('gift_region', $row['name'], ['bölge' => $region], $user['id']);
    $row['region'] = $region;
    return ['ok' => true, 'status' => 200, 'gift' => gift_view($row)];
}

/** API/arayüz için tek tip hediye gösterimi. */
function gift_view(array $row): array
{
    $given = $row['given_at'] !== null;
    return [
        'id'            => (int) $row['id'],
        'name'          => $row['name'],
        'note'          => $row['note'],
        'region'        => $row['region'],
        'given'         => $given,
        'given_time'    => fmt_time($row['given_at']),
        'given_by_name' => $row['given_by_name'],
    ];
}

/**
 * Hediye listesi araması (aktif etkinlik).
 * @param array{q?:string,status?:string} $f  status: all|given|pending
 * @return array{items:list<array>, total:int}
 */
function search_gifts(array $f, int $limit = 50, int $offset = 0): array
{
    $event = active_event();
    if ($event === null) {
        return ['items' => [], 'total' => 0];
    }
    $where = 'g.event_id = ?';
    $params = [$event['id']];
    if (($f['region'] ?? '') === NONE_VALUE) {
        $where .= ' AND g.region IS NULL';
    } elseif (($f['region'] ?? '') !== '') {
        $where .= ' AND g.region = ?';
        $params[] = $f['region'];
    }

    $words = array_slice(array_filter(explode(' ', search_key((string) ($f['q'] ?? '')))), 0, 6);
    foreach ($words as $w) {
        $where .= ' AND g.search_key LIKE ?';
        $params[] = '%' . like_escape($w) . '%';
    }
    $status = $f['status'] ?? 'all';
    if ($status === 'given') {
        $where .= ' AND g.given_at IS NOT NULL';
    } elseif ($status === 'pending') {
        $where .= ' AND g.given_at IS NULL';
    }

    $total = (int) q("SELECT COUNT(*) FROM gifts g WHERE $where", $params)->fetchColumn();
    $limit = max(1, min(200, $limit));
    $offset = max(0, $offset);
    $rows = q(GIFT_SELECT . " WHERE $where ORDER BY g.name, g.id LIMIT $limit OFFSET $offset", $params)->fetchAll();
    return ['items' => array_map('gift_view', $rows), 'total' => $total];
}

/** @return array{total:int, given:int} aktif etkinliğin hediye sayacı */
function gift_stats(): array
{
    $event = active_event();
    if ($event === null) {
        return ['total' => 0, 'given' => 0];
    }
    $r = q('SELECT COUNT(*) AS total, COALESCE(SUM(given_at IS NOT NULL), 0) AS given FROM gifts WHERE event_id = ?', [$event['id']])->fetch();
    return ['total' => (int) $r['total'], 'given' => (int) $r['given']];
}

/**
 * Hediyeyi verdik / geri al. Yarış güvenli: koşullu UPDATE + rowCount kontrolü.
 * Zaten o durumdaysa 409 + güncel view. Aktif etkinlik yoksa 409.
 * @return array{ok:bool, status:int, error?:string, gift?:array}
 */
function mark_gift(array $user, int $id, bool $give): array
{
    if (!can('gift_mark', $user)) {
        return ['ok' => false, 'status' => 403, 'error' => 'Yetkiniz yok.'];
    }
    $event = active_event();
    if ($event === null) {
        return ['ok' => false, 'status' => 409, 'error' => 'Aktif etkinlik yok.'];
    }
    if ($give) {
        $st = q('UPDATE gifts SET given_at = UTC_TIMESTAMP(), given_by = ? WHERE id = ? AND event_id = ? AND given_at IS NULL',
            [$user['id'], $id, $event['id']]);
    } else {
        $st = q('UPDATE gifts SET given_at = NULL, given_by = NULL WHERE id = ? AND event_id = ? AND given_at IS NOT NULL',
            [$id, $event['id']]);
    }
    $row = q(GIFT_SELECT . ' WHERE g.id = ? AND g.event_id = ?', [$id, $event['id']])->fetch();
    if (!$row) {
        return ['ok' => false, 'status' => 404, 'error' => 'Hediye kaydı bulunamadı.'];
    }
    $view = gift_view($row);
    if ($st->rowCount() === 1) {
        return ['ok' => true, 'status' => 200, 'gift' => $view];
    }
    return [
        'ok' => false, 'status' => 409, 'gift' => $view,
        'error' => $give
            ? 'Zaten verildi: ' . ($view['given_by_name'] ?? '?') . ($view['given_time'] !== '' ? ' — ' . $view['given_time'] : '')
            : 'Bu hediye zaten verilmedi olarak işaretli.',
    ];
}

/**
 * Tek hediye kişisini elle ekler. gift_mark yetkisi yeter (onay ekibi sahada ekleyebilsin).
 * Aynı search_key varsa yine eklenir (basit tut, uyarı verme).
 * @return array{ok:bool, status:int, error?:string, gift?:array}
 */
function add_gift(array $user, string $name, ?string $note = null): array
{
    if (!can('gift_mark', $user)) {
        return ['ok' => false, 'status' => 403, 'error' => 'Yetkiniz yok.'];
    }
    $event = active_event();
    if ($event === null) {
        return ['ok' => false, 'status' => 409, 'error' => 'Aktif etkinlik yok.'];
    }
    $clean = clean_text($name, 160);
    if ($clean === null) {
        return ['ok' => false, 'status' => 422, 'error' => 'İsim boş olamaz.'];
    }
    $note = clean_text($note, 190);
    // Not da aranabilsin (ekranda "İsim ya da not ara"): search_key'e notu da kat
    $region = gift_matched_region($clean, gift_region_index($event['id']));
    q('INSERT INTO gifts (event_id, name, search_key, note, region, created_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())',
        [$event['id'], $clean, search_key($clean, $note), $note, $region]);
    $id = (int) db()->lastInsertId();
    audit('gift_add', $clean, $note !== null ? ['not' => $note] : [], $user['id']);
    $row = q(GIFT_SELECT . ' WHERE g.id = ?', [$id])->fetch();
    return ['ok' => true, 'status' => 200, 'gift' => gift_view($row)];
}

/**
 * İsim listesini aktif etkinliğin hediye listesi olarak yükler; mevcut listenin yerine geçer
 * (önce SİLER — yeni liste = temiz liste). Boş/yinelenen (aynı search_key) isimleri atlar.
 * Tek transaction; batch_id için import_batches'e satır açılır. @return eklenen isim sayısı.
 */
function import_gifts(int $eventId, array $names, int $userId): int
{
    $regions = gift_region_index($eventId);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $before = (int) q('SELECT COUNT(*) FROM gifts WHERE event_id = ?', [$eventId])->fetchColumn();
        q('DELETE FROM gifts WHERE event_id = ?', [$eventId]);
        q('INSERT INTO import_batches (event_id, filename, row_count, skipped_count, user_id, created_at) VALUES (?, ?, 0, 0, ?, UTC_TIMESTAMP())',
            [$eventId, 'Hediye listesi', $userId]);
        $batchId = (int) $pdo->lastInsertId();
        $added = 0;
        $seen = [];
        foreach ($names as $name) {
            $clean = clean_text($name, 160);
            if ($clean === null) {
                continue;
            }
            $key = search_key($clean);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            q('INSERT INTO gifts (event_id, name, search_key, batch_id, region, created_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())',
                [$eventId, $clean, $key, $batchId, gift_matched_region($clean, $regions)]);
            $added++;
        }
        q('UPDATE import_batches SET row_count = ? WHERE id = ?', [$added, $batchId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    audit('gifts_import', 'Hediye listesi', ['eski' => $before, 'yeni' => $added], $userId);
    return $added;
}

/** Aktif etkinliğin tüm hediye kayıtlarını siler (transaction). */
function clear_gifts(int $eventId): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        q('DELETE FROM gifts WHERE event_id = ?', [$eventId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
