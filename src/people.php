<?php
declare(strict_types=1);

/*
 * Kişiler, kapsam (scope) ve işaretleme çekirdeği.
 * KURAL: people tablosuna dokunan her sorgu people_scope() kullanır — bölge kısıtı
 * SQL'in içinde uygulanır, "önce oku sonra kontrol et" yapılmaz.
 */

const NONE_VALUE = '__none__'; // filtrelerde "bölgesiz / grupsuz" anlamına gelir

/* ---------- Etkinlik ---------- */

function active_event(): ?array
{
    static $loaded = false, $event = null;
    if (!$loaded) {
        $loaded = true;
        $event = q('SELECT id, name, created_at FROM events WHERE is_active = 1 ORDER BY id DESC LIMIT 1')->fetch() ?: null;
        if ($event) {
            $event['id'] = (int) $event['id'];
        }
    }
    return $event;
}

/** Yeni etkinlik açar; $activate ise diğerlerini pasife alıp bunu aktif yapar. */
function create_event(string $name, int $userId, bool $activate = true): int
{
    $name = clean_text($name, 160) ?? 'Etkinlik';
    $pdo = db();
    $pdo->beginTransaction();
    try {
        q('INSERT INTO events (name, is_active, created_by, created_at) VALUES (?, 0, ?, UTC_TIMESTAMP())', [$name, $userId]);
        $id = (int) $pdo->lastInsertId();
        if ($activate) {
            q('UPDATE events SET is_active = 0 WHERE is_active = 1');
            q('UPDATE events SET is_active = 1 WHERE id = ?', [$id]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $id;
}

function activate_event(int $eventId): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        q('UPDATE events SET is_active = 0 WHERE is_active = 1');
        q('UPDATE events SET is_active = 1 WHERE id = ?', [$eventId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/* ---------- Kapsam ---------- */

/**
 * Kullanıcının görebileceği kişiler için WHERE parçası.
 * $eventId sadece view_all yetkisi olanlar için dikkate alınır (eski etkinliğin exportu vb.);
 * diğer herkes daima aktif etkinliğe kilitlidir.
 *
 * @return array{0:string,1:list<mixed>}
 */
function people_scope(array $user, string $alias = 'p', ?int $eventId = null): array
{
    if ($eventId === null || !can('view_all', $user)) {
        $eventId = active_event()['id'] ?? null;
    }
    if ($eventId === null) {
        return ['1 = 0', []];
    }
    $sql = "$alias.event_id = ?";
    $params = [$eventId];
    if (!can('view_all', $user) && ($user['region'] ?? '') !== '') {
        $sql .= " AND $alias.region = ?";
        $params[] = $user['region'];
    }
    return [$sql, $params];
}

/* ---------- Arama anahtarı ---------- */

/** "Şükrü Öztürk" -> "sukru ozturk": telefonda Türkçe karakter yazmadan da bulunsun. */
function search_key(?string ...$parts): string
{
    $s = implode(' ', array_filter($parts, fn($p) => $p !== null && $p !== ''));
    $s = strtr($s, [
        'Ç' => 'c', 'ç' => 'c', 'Ğ' => 'g', 'ğ' => 'g', 'İ' => 'i', 'I' => 'i', 'ı' => 'i',
        'Ö' => 'o', 'ö' => 'o', 'Ş' => 's', 'ş' => 's', 'Ü' => 'u', 'ü' => 'u',
        'Â' => 'a', 'â' => 'a', 'Î' => 'i', 'î' => 'i', 'Û' => 'u', 'û' => 'u',
    ]);
    $s = mb_strtolower($s, 'UTF-8');
    $s = preg_replace('/[^a-z0-9]+/u', ' ', $s) ?? '';
    return trim($s);
}

/**
 * Tek kişi ekler (import bunu kullanır). $data: full_name (zorunlu), authority, region,
 * group_name, ref_no, extra (array|null). Değerler clean_text'ten geçirilir.
 */
function insert_person(int $eventId, ?int $batchId, array $data): int
{
    $fullName = clean_text($data['full_name'] ?? null, 160);
    if ($fullName === null) {
        throw new InvalidArgumentException('Ad soyad boş olamaz.');
    }
    $refNo = clean_text($data['ref_no'] ?? null, 64);
    $extra = !empty($data['extra']) ? json_encode($data['extra'], JSON_UNESCAPED_UNICODE) : null;
    q(
        'INSERT INTO people (event_id, batch_id, ref_no, full_name, authority, region, group_name, stance, extra, search_key, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())',
        [
            $eventId, $batchId, $refNo, $fullName,
            clean_text($data['authority'] ?? null, 255),
            clean_text($data['region'] ?? null, 120),
            clean_text($data['group_name'] ?? null, 120),
            stance_from_text($data['stance'] ?? null),
            $extra,
            search_key($fullName, $refNo),
        ]
    );
    return (int) db()->lastInsertId();
}

/* ---------- Okuma ---------- */

const PERSON_SELECT = 'SELECT p.id, p.event_id, p.ref_no, p.full_name, p.authority, p.region, p.group_name, p.stance,
        p.attended_at, p.attended_by, u.full_name AS attended_by_name
    FROM people p LEFT JOIN users u ON u.id = p.attended_by';

/** API/arayüz için tek tip kişi gösterimi. */
function person_view(array $row, array $user): array
{
    $attended = $row['attended_at'] !== null;
    $mine = $attended && (int) $row['attended_by'] === $user['id'];
    $canUnmark = false;
    if ($attended) {
        if (can('unmark_any', $user)) {
            $canUnmark = true;
        } elseif ($mine) {
            $age = time() - (new DateTimeImmutable($row['attended_at'], new DateTimeZone('UTC')))->getTimestamp();
            $canUnmark = $age <= 60 * (int) config('unmark_window_minutes', 5);
        }
    }
    return [
        'id'               => (int) $row['id'],
        'full_name'        => $row['full_name'],
        'authority'        => $row['authority'],
        'region'           => $row['region'],
        'group_name'       => $row['group_name'],
        'ref_no'           => $row['ref_no'],
        'attended'         => $attended,
        'attended_time'    => fmt_time($row['attended_at']),
        'attended_by_name' => $row['attended_by_name'],
        'mine'             => $mine,
        'can_unmark'       => $canUnmark,
        // stance yetkisi olmayana hiç gönderilmez (null)
        'stance'           => can('stance', $user) ? stance_view($row['stance']) : null,
    ];
}

function find_person(array $user, int $personId): ?array
{
    [$scope, $params] = people_scope($user);
    $row = q(PERSON_SELECT . " WHERE p.id = ? AND $scope", [$personId, ...$params])->fetch();
    return $row ?: null;
}

/** region / group filtresini SQL'e çevirir. */
function facet_condition(string $column, mixed $value, array &$params): string
{
    if (!is_string($value) || $value === '') {
        return '';
    }
    if ($value === NONE_VALUE) {
        return " AND (p.$column IS NULL OR p.$column = '')";
    }
    $params[] = $value;
    return " AND p.$column = ?";
}

/**
 * @param array{q?:string,region?:string,group?:string,status?:string} $f  status: all|came|pending
 * @return array{items:list<array>, total:int}
 */
function search_people(array $user, array $f, int $limit = 50, int $offset = 0): array
{
    [$where, $params] = people_scope($user);

    $words = array_slice(array_filter(explode(' ', search_key((string) ($f['q'] ?? '')))), 0, 6);
    foreach ($words as $w) {
        $where .= ' AND p.search_key LIKE ?';
        $params[] = '%' . like_escape($w) . '%';
    }
    $where .= facet_condition('region', $f['region'] ?? '', $params);
    $where .= facet_condition('group_name', $f['group'] ?? '', $params);
    if (can('stance', $user) && is_string($f['stance'] ?? null) && $f['stance'] !== '') {
        if ($f['stance'] === NONE_VALUE) {
            $where .= ' AND p.stance IS NULL';
        } elseif (isset(stance_options()[$f['stance']])) {
            $where .= ' AND p.stance = ?';
            $params[] = $f['stance'];
        }
    }
    $status = $f['status'] ?? 'all';
    if ($status === 'came') {
        $where .= ' AND p.attended_at IS NOT NULL';
    } elseif ($status === 'pending') {
        $where .= ' AND p.attended_at IS NULL';
    }

    $total = (int) q("SELECT COUNT(*) FROM people p WHERE $where", $params)->fetchColumn();
    $limit = max(1, min(200, $limit));
    $offset = max(0, $offset);
    $rows = q(PERSON_SELECT . " WHERE $where ORDER BY p.full_name, p.id LIMIT $limit OFFSET $offset", $params)->fetchAll();

    return ['items' => array_map(fn($r) => person_view($r, $user), $rows), 'total' => $total];
}

/** @return array{total:int, came:int} kullanıcının kapsamındaki sayaç */
function scope_stats(array $user): array
{
    [$where, $params] = people_scope($user);
    $r = q("SELECT COUNT(*) AS total, COALESCE(SUM(p.attended_at IS NOT NULL), 0) AS came FROM people p WHERE $where", $params)->fetch();
    return ['total' => (int) $r['total'], 'came' => (int) $r['came']];
}

/**
 * Bölge ya da gruba göre kırılım (filtre çipleri + yönetici paneli).
 * @param 'region'|'group_name' $column
 * @return list<array{value:string, label:string, total:int, came:int}>  value=NONE_VALUE -> boş olanlar
 */
function people_breakdown(array $user, string $column, ?int $eventId = null): array
{
    if (!in_array($column, ['region', 'group_name'], true)) {
        throw new InvalidArgumentException('Geçersiz kolon');
    }
    [$where, $params] = people_scope($user, 'p', $eventId);
    $rows = q(
        "SELECT NULLIF(p.$column, '') AS v, COUNT(*) AS total, COALESCE(SUM(p.attended_at IS NOT NULL), 0) AS came
         FROM people p WHERE $where GROUP BY v ORDER BY v IS NULL, v",
        $params
    )->fetchAll();
    $none = $column === 'region' ? 'Bölgesiz' : 'Grupsuz';
    return array_map(fn($r) => [
        'value' => $r['v'] ?? NONE_VALUE,
        'label' => $r['v'] ?? $none,
        'total' => (int) $r['total'],
        'came'  => (int) $r['came'],
    ], $rows);
}

/* ---------- "Bizimle mi?" ---------- */

/** @return array<string, array{0:string,1:string}> anahtar => [etiket, renk] */
function stance_options(): array
{
    return (array) config('stance_options', []);
}

/** @return array{value:?string,label:string,color:string} */
function stance_view(?string $value): array
{
    $opt = $value !== null ? (stance_options()[$value] ?? null) : null;
    return $opt
        ? ['value' => $value, 'label' => $opt[0], 'color' => $opt[1]]
        : ['value' => null, 'label' => 'Belirsiz', 'color' => 'muted'];
}

/** Excel'deki serbest metni ("Bizimle", "bizimle", "KARŞI") seçenek anahtarına çevirir; eşleşmezse null. */
function stance_from_text(mixed $text): ?string
{
    $k = search_key(clean_text($text, 64));
    if ($k === '') {
        return null;
    }
    foreach (stance_options() as $key => [$label]) {
        if ($k === search_key($key) || $k === search_key($label)) {
            return $key;
        }
    }
    return null;
}

/**
 * @param ?string $value seçenek anahtarı ya da null (temizle)
 * @return array{ok:bool, status:int, error?:string, person?:array}
 */
function set_stance(array $user, int $personId, ?string $value): array
{
    if (!can('stance', $user)) {
        return ['ok' => false, 'status' => 403, 'error' => 'Yetkiniz yok.'];
    }
    if ($value !== null && !isset(stance_options()[$value])) {
        return ['ok' => false, 'status' => 422, 'error' => 'Geçersiz seçenek.'];
    }
    $event = active_event();
    if ($event === null) {
        return ['ok' => false, 'status' => 409, 'error' => 'Aktif etkinlik yok.'];
    }
    [$scope, $params] = people_scope($user);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $st = q("UPDATE people p SET p.stance = ? WHERE p.id = ? AND $scope", [$value, $personId, ...$params]);
        if ($st->rowCount() === 0 && find_person($user, $personId) === null) {
            $pdo->rollBack();
            return ['ok' => false, 'status' => 404, 'error' => 'Kişi bulunamadı.'];
        }
        if ($st->rowCount() === 1) {
            q(
                'INSERT INTO attendance_log (event_id, person_id, user_id, action, note, ip, created_at) VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())',
                [$event['id'], $personId, $user['id'], 'stance', $value, client_ip_bin()]
            );
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return ['ok' => true, 'status' => 200, 'person' => person_view(find_person($user, $personId), $user)];
}

/* ---------- Excel yardımcıları ---------- */

/**
 * "MEHMET GENÇ-06.01.1980-ÖMERLİ,ORHAN DOĞAN-10.02.1963-ŞİRAN" -> "MEHMET GENÇ, ORHAN DOĞAN"
 * Oda kayıtlarındaki doğum tarihi/yeri gibi kişisel ayrıntıları atar, sadece isimleri bırakır.
 */
function names_only(mixed $text): ?string
{
    $s = clean_text($text, 2000);
    if ($s === null || strtoupper($s) === 'NULL') {
        return null;
    }
    $names = [];
    foreach (preg_split('/\s*[,;]\s*/u', $s) as $part) {
        $name = trim(preg_split('/\s*-\s*\d/u', $part)[0] ?? '');
        if ($name !== '' && !in_array($name, $names, true)) {
            $names[] = $name;
        }
    }
    return $names ? mb_substr(implode(', ', $names), 0, 255, 'UTF-8') : null;
}

/* ---------- İşaretleme ---------- */

/** @return array{ok:bool, status:int, error?:string, person?:array} */
function mark_person(array $user, int $personId): array
{
    if (!can('mark', $user)) {
        return ['ok' => false, 'status' => 403, 'error' => 'Yetkiniz yok.'];
    }
    $event = active_event();
    if ($event === null) {
        return ['ok' => false, 'status' => 409, 'error' => 'Aktif etkinlik yok.'];
    }
    [$scope, $params] = people_scope($user);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $st = q(
            "UPDATE people p SET p.attended_at = UTC_TIMESTAMP(), p.attended_by = ?
             WHERE p.id = ? AND p.attended_at IS NULL AND $scope",
            [$user['id'], $personId, ...$params]
        );
        if ($st->rowCount() === 1) {
            q(
                'INSERT INTO attendance_log (event_id, person_id, user_id, action, ip, created_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())',
                [$event['id'], $personId, $user['id'], 'mark', client_ip_bin()]
            );
            $pdo->commit();
            return ['ok' => true, 'status' => 200, 'person' => person_view(find_person($user, $personId), $user)];
        }
        $pdo->rollBack();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    $row = find_person($user, $personId);
    if ($row === null) {
        return ['ok' => false, 'status' => 404, 'error' => 'Kişi bulunamadı.'];
    }
    $view = person_view($row, $user);
    return [
        'ok' => false, 'status' => 409, 'person' => $view,
        'error' => 'Zaten işaretli: ' . ($view['attended_by_name'] ?? '?') . ' — ' . $view['attended_time'],
    ];
}

/** @return array{ok:bool, status:int, error?:string, person?:array} */
function unmark_person(array $user, int $personId): array
{
    if (!can('mark', $user)) {
        return ['ok' => false, 'status' => 403, 'error' => 'Yetkiniz yok.'];
    }
    $event = active_event();
    if ($event === null) {
        return ['ok' => false, 'status' => 409, 'error' => 'Aktif etkinlik yok.'];
    }
    [$scope, $params] = people_scope($user);
    $own = '';
    $ownParams = [];
    if (!can('unmark_any', $user)) {
        $own = ' AND p.attended_by = ? AND p.attended_at >= UTC_TIMESTAMP() - INTERVAL ? MINUTE';
        $ownParams = [$user['id'], (int) config('unmark_window_minutes', 5)];
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $st = q(
            "UPDATE people p SET p.attended_at = NULL, p.attended_by = NULL
             WHERE p.id = ? AND p.attended_at IS NOT NULL AND $scope$own",
            [$personId, ...$params, ...$ownParams]
        );
        if ($st->rowCount() === 1) {
            q(
                'INSERT INTO attendance_log (event_id, person_id, user_id, action, ip, created_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())',
                [$event['id'], $personId, $user['id'], 'unmark', client_ip_bin()]
            );
            $pdo->commit();
            return ['ok' => true, 'status' => 200, 'person' => person_view(find_person($user, $personId), $user)];
        }
        $pdo->rollBack();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    $row = find_person($user, $personId);
    if ($row === null) {
        return ['ok' => false, 'status' => 404, 'error' => 'Kişi bulunamadı.'];
    }
    if ($row['attended_at'] === null) {
        return ['ok' => false, 'status' => 409, 'person' => person_view($row, $user), 'error' => 'Bu kişi zaten işaretsiz.'];
    }
    $min = (int) config('unmark_window_minutes', 5);
    return [
        'ok' => false, 'status' => 403, 'person' => person_view($row, $user),
        'error' => "Sadece kendi işaretinizi $min dakika içinde geri alabilirsiniz. Yöneticiye başvurun.",
    ];
}
