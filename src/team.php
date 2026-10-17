<?php
declare(strict_types=1);

/* ---------- Takım: "kimin adamı" ----------
 * Kullanıcı (onaycı / yönetici / admin) listeden firmaları kendi takımına alır; o kişilerden sorumludur.
 * Birim = firma grubu (onay kartı = firm_key; insert sonrası değişmez). Etkinliğe bağlıdır.
 * Aynı firma en fazla config('team_max', 2) kullanıcının takımında olabilir: doluysa biri çıkarmalı.
 * Sadece AKTİF kullanıcılar sayılır/görünür; pasife alınınca üyelikleri silinir (team_clear_user).
 */

function team_max(): int
{
    return max(1, (int) config('team_max', 2));
}

/**
 * Firma(lar)ın takım sahipleri — tek sorgu (kartlar için toplu).
 * @param list<string> $keys
 * @return array<string, list<array{id:int, name:string}>> firm_key => sahipler (eklenme sırasıyla)
 */
function team_owners(int $eventId, array $keys): array
{
    $keys = array_values(array_unique($keys));
    if (!$keys) {
        return [];
    }
    $in = implode(',', array_fill(0, count($keys), '?'));
    $out = [];
    foreach (q("SELECT t.firm_key, u.id, u.full_name FROM team_members t JOIN users u ON u.id = t.user_id AND u.is_active = 1
                WHERE t.event_id = ? AND t.firm_key IN ($in) ORDER BY t.created_at, t.user_id", [$eventId, ...$keys])->fetchAll() as $r) {
        $out[$r['firm_key']][] = ['id' => (int) $r['id'], 'name' => $r['full_name']];
    }
    return $out;
}

/**
 * Kartın "team" alanı. Yetkisi olmayana null.
 * @param list<array{id:int,name:string}> $owners
 */
function team_view(array $owners, array $user): ?array
{
    if (!can('team', $user)) {
        return null;
    }
    $mine = false;
    $members = [];
    foreach ($owners as $o) {
        $isMe = $o['id'] === $user['id'];
        $mine = $mine || $isMe;
        $members[] = ['id' => $o['id'], 'name' => $o['name'], 'mine' => $isMe];
    }
    return ['members' => $members, 'mine' => $mine, 'full' => count($owners) >= team_max(), 'max' => team_max()];
}

/** people_where için "kimin adamı" koşulu: kullanıcı id'si ya da NONE_VALUE (sahipsiz). */
function team_condition(mixed $owner, array &$params): string
{
    if (!is_string($owner) || $owner === '') {
        return '';
    }
    $exists = 'EXISTS (SELECT 1 FROM team_members t JOIN users tu ON tu.id = t.user_id AND tu.is_active = 1
                       WHERE t.event_id = p.event_id AND t.firm_key = p.firm_key';
    if ($owner === NONE_VALUE) {
        return " AND NOT $exists)";
    }
    if (!ctype_digit($owner)) {
        return ' AND 1 = 0';
    }
    $params[] = (int) $owner;
    return " AND $exists AND t.user_id = ?)";
}

/**
 * "Kimin adamı" filtre çipleri — FİRMA bazında, kullanıcının kapsamında.
 * İlk çip daima kullanıcının kendisi ("Benimkiler"), sonra takımı olan diğer aktif kullanıcılar (ada göre),
 * en sonda "Sahipsiz".
 * @return list<array{value:string, label:string, total:int}>
 */
function team_breakdown(array $user): array
{
    if (!can('team', $user)) {
        return [];
    }
    [$where, $params] = people_scope($user, 'p');
    $rows = q("SELECT u.id, u.full_name, COUNT(DISTINCT p.firm_key) AS total
               FROM people p
               JOIN team_members t ON t.event_id = p.event_id AND t.firm_key = p.firm_key
               JOIN users u ON u.id = t.user_id AND u.is_active = 1
               WHERE $where GROUP BY u.id, u.full_name ORDER BY u.full_name", $params)->fetchAll();
    $mine = 0;
    $others = [];
    foreach ($rows as $r) {
        if ((int) $r['id'] === $user['id']) {
            $mine = (int) $r['total'];
        } else {
            $others[] = ['value' => (string) $r['id'], 'label' => $r['full_name'], 'total' => (int) $r['total']];
        }
    }
    $np = [];
    $none = (int) q("SELECT COUNT(DISTINCT p.firm_key) FROM people p WHERE $where" . team_condition(NONE_VALUE, $np), $params)->fetchColumn();
    return [
        ['value' => (string) $user['id'], 'label' => 'Benimkiler', 'total' => $mine],
        ...$others,
        ['value' => NONE_VALUE, 'label' => 'Sahipsiz', 'total' => $none],
    ];
}

/**
 * Etkinlik başına tek sıra (adlı kilit): farklı bölgedeki iki onaycı aynı anda "son yeri" alamasın.
 * Firma başına değil: firm_key eşitliği DB kolasyonuna göre (büyük/küçük harf duyarsız), ham anahtarın
 * md5'i ise duyarlı — aynı firmaya iki ayrı kilit düşebilirdi. Takım işlemleri kısa ve seyrek; tek sıra yeter.
 */
function with_team_lock(int $eventId, callable $fn): mixed
{
    $name = 'secim_team_' . $eventId;
    if ((int) q('SELECT GET_LOCK(?, 10)', [$name])->fetchColumn() !== 1) {
        throw new BusyException('Sistem meşgul, birkaç saniye sonra tekrar deneyin.');
    }
    try {
        return $fn();
    } finally {
        q('SELECT RELEASE_LOCK(?)', [$name]);
    }
}

/**
 * Kişinin firmasını kullanıcının takımına alır.
 * @return array{ok:bool, status:int, error?:string, person?:array}
 */
function team_add(array $user, int $personId): array
{
    if (!can('team', $user)) {
        return ['ok' => false, 'status' => 403, 'error' => 'Yetkiniz yok.'];
    }
    $event = active_event();
    if ($event === null) {
        return ['ok' => false, 'status' => 409, 'error' => 'Aktif liste yok.'];
    }
    $person = find_person($user, $personId);
    if ($person === null) {
        return ['ok' => false, 'status' => 404, 'error' => 'Kişi bulunamadı.'];
    }
    $key = $person['firm_key'];
    $err = with_team_lock($event['id'], function () use ($event, $key, $user): ?array {
        $owners = team_owners($event['id'], [$key])[$key] ?? [];
        if (in_array($user['id'], array_column($owners, 'id'), true)) {
            return ['status' => 409, 'error' => 'Zaten takımınızda.'];
        }
        if (count($owners) >= team_max()) {
            return ['status' => 409, 'error' => 'Bu kişi zaten ' . count($owners) . ' kişinin takımında ('
                . implode(', ', array_column($owners, 'name')) . '). Biri çıkarmadan eklenemez.'];
        }
        // INSERT ... SELECT: kullanıcı satırını okurken kilitler. Aynı anda pasife alınırsa ya önce eklenir
        // (ardından team_clear_user siler) ya da pasifi görür, hiç eklemez — pasif kullanıcıya üyelik kalmaz.
        $n = q('INSERT INTO team_members (event_id, firm_key, user_id, created_at)
                SELECT ?, ?, u.id, UTC_TIMESTAMP() FROM users u WHERE u.id = ? AND u.is_active = 1
                ON DUPLICATE KEY UPDATE created_at = UTC_TIMESTAMP()', [$event['id'], $key, $user['id']])->rowCount();
        return $n > 0 ? null : ['status' => 403, 'error' => 'Hesabınız pasif.'];
    });
    if ($err !== null) {
        return ['ok' => false, 'status' => $err['status'], 'error' => $err['error'],
                'person' => group_view(find_group($user, $personId), $user)];
    }
    audit('team_add', $person['authority'] ?: $person['full_name'], ['kişi' => $personId]);
    return ['ok' => true, 'status' => 200, 'person' => group_view(find_group($user, $personId), $user)];
}

/**
 * Takımdan çıkarır. Kendi kaydını herkes; başkasınınkini team_manage yetkisi olan.
 * @return array{ok:bool, status:int, error?:string, person?:array}
 */
function team_remove(array $user, int $personId, ?int $ownerId = null): array
{
    $ownerId ??= $user['id'];
    if (!can('team', $user) || ($ownerId !== $user['id'] && !can('team_manage', $user))) {
        return ['ok' => false, 'status' => 403, 'error' => 'Yetkiniz yok.'];
    }
    $event = active_event();
    if ($event === null) {
        return ['ok' => false, 'status' => 409, 'error' => 'Aktif liste yok.'];
    }
    $person = find_person($user, $personId);
    if ($person === null) {
        return ['ok' => false, 'status' => 404, 'error' => 'Kişi bulunamadı.'];
    }
    $key = $person['firm_key'];
    $n = with_team_lock($event['id'], fn(): int => q(
        'DELETE FROM team_members WHERE event_id = ? AND firm_key = ? AND user_id = ?',
        [$event['id'], $key, $ownerId]
    )->rowCount());
    if ($n === 0) {
        return ['ok' => false, 'status' => 409, 'error' => 'Bu kişi o takımda değil.',
                'person' => group_view(find_group($user, $personId), $user)];
    }
    $detail = ['kişi' => $personId];
    if ($ownerId !== $user['id']) {
        $detail['takım sahibi'] = (string) q('SELECT username FROM users WHERE id = ?', [$ownerId])->fetchColumn();
    }
    audit('team_remove', $person['authority'] ?: $person['full_name'], $detail);
    return ['ok' => true, 'status' => 200, 'person' => group_view(find_group($user, $personId), $user)];
}

/** Kullanıcı pasife alınınca: tüm takım üyelikleri silinir (yeniden aktif olunca geri gelmez). */
function team_clear_user(int $userId): int
{
    return q('DELETE FROM team_members WHERE user_id = ?', [$userId])->rowCount();
}

/**
 * "Panelim" verisi — kullanıcının KENDİ takımı (firma bazında, kapsamında). Sayfanın ilk çizimi ve
 * arka plan yenilemesi (api/panel.php) aynı kaynaktan okur.
 * @return array{stats:array{total:int,came:int,pending:int,rate:int}, region:list<array>, pending:list<array>,
 *               came:list<array>, truncated:bool}
 */
function my_panel_data(array $user): array
{
    $f = ['owner' => (string) $user['id']];
    [$where, $params] = people_where($user, $f);
    $r = q("SELECT COUNT(*) AS total, COALESCE(SUM(all_came), 0) AS came FROM (
                SELECT MIN(p.attended_at IS NOT NULL) AS all_came FROM people p WHERE $where GROUP BY p.firm_key
            ) t", $params)->fetch();
    $total = (int) $r['total'];
    $came = (int) $r['came'];
    $rate = fn(int $t, int $c): int => $t ? (int) round($c / $t * 100) : 0;

    $region = [];
    foreach (q("SELECT v, COUNT(*) AS total, COALESCE(SUM(all_came), 0) AS came FROM (
                    SELECT NULLIF(p.region, '') AS v, p.firm_key, MIN(p.attended_at IS NOT NULL) AS all_came
                    FROM people p WHERE $where GROUP BY v, p.firm_key
                ) t GROUP BY v ORDER BY v IS NULL, v", $params)->fetchAll() as $b) {
        $region[] = ['label' => $b['v'] ?? 'Bölgesiz', 'total' => (int) $b['total'], 'came' => (int) $b['came'],
                     'rate' => $rate((int) $b['total'], (int) $b['came'])];
    }

    // Kişi listeleri: her biri en fazla 200 firma (fazlası onay ekranında "Benimkiler" filtresiyle).
    // Rozetler listeyi değil yukarıdaki doğru sayıları (stats) gösterir.
    // "pending" filtresi = en az bir şubesi gelmemiş firma (= tam gelmemiş); "came" kısmi gelenleri de verir → attended ile süz.
    $limit = 200;
    $slim = fn(array $p): array => [
        'id'         => $p['id'],
        'name'       => $p['authority'] ?: $p['full_name'],
        'firm'       => $p['authority'] ? $p['full_name'] : null,
        'region'     => $p['region'],
        'phone'      => $p['phone'],
        'phone_text' => $p['phone_text'],
        'time'       => $p['attended_time'],
        'by'         => $p['attended_by_name'],
        'partial'    => $p['came_count'] > 0 && !$p['attended'] ? $p['came_count'] . '/' . $p['count'] : null,
    ];
    $pending = array_map($slim, search_people($user, $f + ['status' => 'pending'], $limit)['items']);
    $cameList = [];
    // kısmi gelenler "came" sorgusunda da çıkar; 200 tam gelen dolana kadar sayfa sayfa
    for ($offset = 0; count($cameList) < min($limit, $came); $offset += $limit) {
        $page = search_people($user, $f + ['status' => 'came'], $limit, $offset)['items'];
        foreach ($page as $p) {
            if ($p['attended'] && count($cameList) < $limit) {
                $cameList[] = $slim($p);
            }
        }
        if (count($page) < $limit) {
            break;
        }
    }

    return [
        'stats'     => ['total' => $total, 'came' => $came, 'pending' => $total - $came, 'rate' => $rate($total, $came)],
        'region'    => $region,
        'pending'   => $pending,
        'came'      => $cameList,
        'truncated' => count($pending) < $total - $came || count($cameList) < $came,
    ];
}

/**
 * Yönetim paneli "Takımlar" bölümü: her kullanıcının takımı (firma bazında) ve gelmeyen adamları.
 * Kapsam = görenin kapsamı (yönetici/admin: tümü). Sadece aktif kullanıcılar.
 * @return array{summary:array{firms:int,owned:int,unowned:int,full:int,owners:int,owned_came:int,max:int},
 *               users:list<array{id:int,name:string,role:string,total:int,came:int,pending:int,rate:int,waiting:list<array>,more:int}>}
 */
function team_overview(array $user, int $eventId): array
{
    [$where, $params] = people_scope($user, 'p', $eventId);
    $rate = fn(int $t, int $c): int => $t ? (int) round($c / $t * 100) : 0;
    $join = 'JOIN team_members t ON t.event_id = p.event_id AND t.firm_key = p.firm_key
             JOIN users u ON u.id = t.user_id AND u.is_active = 1';

    // Firma başına: kaç aktif sahip, hepsi geldi mi
    $firms = q("SELECT COUNT(*) AS firms, COALESCE(SUM(owners > 0), 0) AS owned, COALESCE(SUM(owners >= ?), 0) AS full,
                       COALESCE(SUM(owners > 0 AND all_came = 1), 0) AS owned_came
                FROM (
                    SELECT p.firm_key, MIN(p.attended_at IS NOT NULL) AS all_came,
                           (SELECT COUNT(*) FROM team_members t JOIN users u ON u.id = t.user_id AND u.is_active = 1
                            WHERE t.event_id = MIN(p.event_id) AND t.firm_key = p.firm_key) AS owners
                    FROM people p WHERE $where GROUP BY p.firm_key
                ) f", [team_max(), ...$params])->fetch();

    $users = [];
    foreach (q("SELECT x.user_id, u.full_name, u.role, COUNT(*) AS total, COALESCE(SUM(x.all_came), 0) AS came
                FROM (
                    SELECT t.user_id, p.firm_key, MIN(p.attended_at IS NOT NULL) AS all_came
                    FROM people p $join WHERE $where GROUP BY t.user_id, p.firm_key
                ) x JOIN users u ON u.id = x.user_id
                GROUP BY x.user_id, u.full_name, u.role
                ORDER BY total DESC, u.full_name", $params)->fetchAll() as $r) {
        $t = (int) $r['total'];
        $c = (int) $r['came'];
        $users[(int) $r['user_id']] = ['id' => (int) $r['user_id'], 'name' => $r['full_name'], 'role' => ROLES[$r['role']] ?? $r['role'],
            'total' => $t, 'came' => $c, 'pending' => $t - $c, 'rate' => $rate($t, $c), 'waiting' => [], 'more' => 0];
    }

    // Her kullanıcının gelmeyen adamları (kişi başı en fazla 50; kalanı "+N")
    $show = 50;
    foreach (q("SELECT t.user_id, p.firm_key,
                       MIN(COALESCE(NULLIF(p.authority, ''), p.full_name)) AS name,
                       MIN(NULLIF(p.region, '')) AS region
                FROM people p $join WHERE $where
                GROUP BY t.user_id, p.firm_key
                HAVING MIN(p.attended_at IS NOT NULL) = 0
                ORDER BY name", $params)->fetchAll() as $w) {
        $uid = (int) $w['user_id'];
        if (!isset($users[$uid])) {
            continue;
        }
        if (count($users[$uid]['waiting']) < $show) {
            $users[$uid]['waiting'][] = ['name' => $w['name'], 'region' => $w['region']];
        } else {
            $users[$uid]['more']++;
        }
    }

    return [
        'summary' => [
            'firms'      => (int) $firms['firms'],
            'owned'      => (int) $firms['owned'],
            'unowned'    => (int) $firms['firms'] - (int) $firms['owned'],
            'full'       => (int) $firms['full'],
            'owned_came' => (int) $firms['owned_came'],
            'owners'     => count($users),
            'max'        => team_max(),
        ],
        'users' => array_values($users),
    ];
}
