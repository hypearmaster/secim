<?php
declare(strict_types=1);

/*
 * Kişiler, kapsam (scope) ve işaretleme çekirdeği.
 * KURAL: people tablosuna dokunan her sorgu people_scope() kullanır — bölge kısıtı
 * SQL'in içinde uygulanır, "önce oku sonra kontrol et" yapılmaz.
 */

const NONE_VALUE = '__none__'; // filtrelerde "bölgesiz / grupsuz" anlamına gelir

/* ---------- Etkinlik ---------- */

function active_event(bool $refresh = false): ?array
{
    static $loaded = false, $event = null;
    if (!$loaded || $refresh) {
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
        // Kendi bölgesi + bölgesiz kayıtlar (örn. Bizimkiler'den "bağımsız" eklenenler):
        // bölgesi olmayan kişiyi kapıdaki her onaycı bulabilmeli. Başka bölge görünmez.
        $sql .= " AND ($alias.region = ? OR $alias.region IS NULL OR $alias.region = '')";
        $params[] = $user['region'];
    }
    return [$sql, $params];
}

/* ---------- Arama anahtarı ---------- */

/** "Şükrü Öztürk" -> "sukru ozturk": telefonda Türkçe karakter yazmadan da bulunsun. Ad + yetkili + sicil no. */
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
    $authority = clean_text($data['authority'] ?? null, 255);
    $extra = !empty($data['extra']) ? json_encode($data['extra'], JSON_UNESCAPED_UNICODE) : null;
    q(
        'INSERT INTO people (event_id, batch_id, ref_no, phone, full_name, authority, region, group_name, stance, extra, search_key, firm_key, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())',
        [
            $eventId, $batchId, $refNo, normalize_phone($data['phone'] ?? null), $fullName,
            $authority,
            clean_text($data['region'] ?? null, 120),
            clean_text($data['group_name'] ?? null, 120),
            stance_from_text($data['stance'] ?? null),
            $extra,
            search_key($fullName, $authority, $refNo), // yetkili adıyla da bulunsun
            firm_key($fullName, $authority, $refNo),
        ]
    );
    return (int) db()->lastInsertId();
}

/**
 * Aynı firma (şubeleri dahil) = aynı yetkili + unvanın ilk 3 kelimesi aynı.
 * "FLO MAĞAZACILIK ... AGORA AVM ŞUBESİ" ile "FLO MAĞAZACILIK ... KEPEZ CADDE ŞUBESİ" tek kart olur;
 * aynı kişinin bambaşka şirketi ayrı kalır. Yetkilisi yazmayan kayıt birleştirilmez (sicil no ile tekil).
 */
function firm_key(string $fullName, ?string $authority, ?string $refNo): string
{
    $a = search_key($authority);
    if ($a !== '') {
        return mb_substr('a:' . $a . '|' . implode(' ', array_slice(explode(' ', search_key($fullName)), 0, 3)), 0, 255);
    }
    // Yer tutucu sicil (elle eklenen, sicilsiz): her biri kendi kartı. Aynı sicil sayılsa hepsi tek
    // kartta birleşir, isimle birleşse "yine de ekle" denen aynı adlı iki farklı kişi tek GELDİ'yle işaretlenir.
    if ($refNo !== null && $refNo === (string) config('placeholder_ref', '11111111')) {
        return 'm:' . bin2hex(random_bytes(8));
    }
    return mb_substr($refNo !== null && $refNo !== '' ? 'r:' . $refNo : 'n:' . search_key($fullName), 0, 255);
}

/* ---------- Telefon ---------- */

/**
 * Excel'deki / elle yazılan numarayı aranabilir biçime çevirir: "0532 123 45 67", "5321234567",
 * "+90 (532) 123-45-67" -> "+905321234567". +'lı yabancı numara rakamlarıyla kalır.
 * Numara gibi görünmüyorsa null.
 */
function normalize_phone(mixed $value): ?string
{
    $s = clean_text($value, 40);
    if ($s === null) {
        return null;
    }
    $intl = str_starts_with($s, '+') || str_starts_with($s, '00');
    $d = preg_replace('/\D+/', '', $s) ?? '';
    if (str_starts_with($s, '00')) {
        $d = substr($d, 2);
    }
    return match (true) {
        strlen($d) === 10 && $d[0] !== '0'          => '+90' . $d,            // 5321234567 / 2421234567
        strlen($d) === 11 && $d[0] === '0'          => '+90' . substr($d, 1), // 05321234567
        strlen($d) === 12 && str_starts_with($d, '90') => '+' . $d,           // 905321234567
        $intl && strlen($d) >= 8 && strlen($d) <= 15 => '+' . $d,             // yabancı numara
        default => null,
    };
}

/** "+905321234567" -> "0532 123 45 67" (ekranda / ipucunda). */
function phone_display(?string $phone): string
{
    if ($phone === null || $phone === '') {
        return '';
    }
    if (preg_match('/^\+90(\d{3})(\d{3})(\d{2})(\d{2})$/', $phone, $m)) {
        return "0$m[1] $m[2] $m[3] $m[4]";
    }
    return $phone;
}

/** Cep numarası mı (Türkiye 5xx)? Firmada hem sabit hem cep varsa cep tercih edilir. */
function is_mobile_phone(?string $phone): bool
{
    return $phone !== null && str_starts_with($phone, '+905');
}

/* ---------- Okuma ---------- */

const PERSON_SELECT = 'SELECT p.id, p.event_id, p.firm_key, p.ref_no, p.phone, p.full_name, p.authority, p.region, p.group_name, p.stance,
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
        // numara sadece arama yetkisi olana gider (kapsam dışındaki kişinin zaten kartı yok)
        'phone'            => can('call', $user) ? ($row['phone'] ?? null) : null,
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

/** Kişinin firma grubundaki, kullanıcının kapsamındaki tüm kayıtlar (yoksa []). */
function find_group(array $user, int $personId): array
{
    $one = find_person($user, $personId);
    if ($one === null) {
        return [];
    }
    [$scope, $params] = people_scope($user);
    return q(PERSON_SELECT . " WHERE p.firm_key = ? AND $scope ORDER BY p.full_name, p.id", [$one['firm_key'], ...$params])->fetchAll();
}

/**
 * Bir firma grubunun kartı. Tek kayıtta person_view ile aynı alanlar; ek olarak
 * count / came_count / firms (şube unvanları) / regions. id = gruptaki ilk kaydın id'si.
 */
function group_view(array $members, array $user): array
{
    $views = array_map(fn($r) => person_view($r, $user), $members);
    $first = $views[0];
    $came = array_values(array_filter($views, fn($v) => $v['attended']));
    $lastCame = $came ? $came[count($came) - 1] : null;
    $regions = array_values(array_unique(array_filter(array_column($views, 'region'))));
    $groups = array_values(array_unique(array_filter(array_column($views, 'group_name'))));
    $refs = array_values(array_filter(array_column($views, 'ref_no')));
    $phones = array_values(array_unique(array_filter(array_column($views, 'phone'))));
    usort($phones, fn($a, $b) => is_mobile_phone($b) <=> is_mobile_phone($a)); // cep önce
    return [
        'id'               => $first['id'],
        'count'            => count($views),
        'came_count'       => count($came),
        'full_name'        => $first['full_name'],
        'firms'            => count($views) > 1 ? array_column($views, 'full_name') : [],
        'authority'        => $first['authority'],
        'region'           => $regions[0] ?? null,
        'regions'          => $regions,
        'group_name'       => implode(', ', $groups) ?: null,
        'ref_no'           => count($refs) > 1 ? count($refs) . ' sicil' : ($refs[0] ?? null),
        'phone'            => $phones[0] ?? null,
        'phone_text'       => phone_display($phones[0] ?? null),
        'attended'         => count($came) === count($views),          // hepsi geldi
        'attended_time'    => $lastCame['attended_time'] ?? '',
        'attended_by_name' => $lastCame['attended_by_name'] ?? null,
        'mine'             => (bool) array_filter($came, fn($v) => $v['mine']),
        'can_unmark'       => (bool) array_filter($came, fn($v) => $v['can_unmark']),
        'stance'           => $first['stance'],
    ];
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
 * Kapsam + arama/filtre koşulu. Onay ekranı ve dışa aktarma AYNI kuralları kullanır.
 * @param array{q?:string,region?:string,group?:string,status?:string,stance?:string} $f  status: all|came|pending
 * @return array{0:string,1:list<mixed>}
 */
function people_where(array $user, array $f, ?int $eventId = null): array
{
    [$where, $params] = people_scope($user, 'p', $eventId);

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
    return [$where, $params];
}

/**
 * @return array{items:list<array>, total:int}
 */
function search_people(array $user, array $f, int $limit = 50, int $offset = 0): array
{
    [$where, $params] = people_where($user, $f);

    // Sonuçlar firma grubuna göre: filtreye uyan en az bir kaydı olan grup bir kart olur
    $total = (int) q("SELECT COUNT(DISTINCT p.firm_key) FROM people p WHERE $where", $params)->fetchColumn();
    $limit = max(1, min(200, $limit));
    $offset = max(0, $offset);
    $keys = q("SELECT p.firm_key FROM people p WHERE $where GROUP BY p.firm_key
               ORDER BY MIN(COALESCE(p.authority, p.full_name)), MIN(p.id) LIMIT $limit OFFSET $offset", $params)->fetchAll(PDO::FETCH_COLUMN);
    if (!$keys) {
        return ['items' => [], 'total' => $total];
    }
    // Kartta grubun kapsamdaki TÜM kayıtları görünür (sadece aramaya uyanlar değil)
    [$scope, $sp] = people_scope($user);
    $in = implode(',', array_fill(0, count($keys), '?'));
    $byKey = [];
    foreach (q(PERSON_SELECT . " WHERE $scope AND p.firm_key IN ($in) ORDER BY p.full_name, p.id", [...$sp, ...$keys])->fetchAll() as $r) {
        $byKey[$r['firm_key']][] = $r;
    }
    $items = [];
    foreach ($keys as $k) {
        if (!empty($byKey[$k])) {
            $items[] = group_view($byKey[$k], $user);
        }
    }
    return ['items' => $items, 'total' => $total];
}

/** @return array{total:int, came:int} kullanıcının kapsamındaki sayaç */
function scope_stats(array $user): array
{
    [$where, $params] = people_scope($user);
    // FİRMA bazında (şubeler tek): onay kartlarıyla birebir. Firma "geldi" = kapsamdaki TÜM şubeleri
    // işaretli (yeşil kart). MIN(...) = 1 ancak tüm şubeler geldiyse.
    $r = q("SELECT COUNT(*) AS total, COALESCE(SUM(all_came), 0) AS came FROM (
                SELECT MIN(p.attended_at IS NOT NULL) AS all_came FROM people p WHERE $where GROUP BY p.firm_key
            ) t", $params)->fetch();
    return ['total' => (int) $r['total'], 'came' => (int) $r['came']];
}

/**
 * "Bizimle mi?" kırılımı — FİRMA bazında (şubeler tek). Onay ekranı çipleri + panel için.
 * Her firma TEK kez sayılır: firmanın stance'i temsilci şubesindir (MIN(id); şubeler normalde
 * aynı stance'tedir). came = kapsamdaki tüm şubeleri işaretli (all-came; scope_stats ile aynı).
 * @return array<string, array{total:int, came:int}> stance anahtarı (null için NONE_VALUE) => sayılar
 */
function stance_breakdown(array $user, ?int $eventId = null): array
{
    [$where, $params] = people_scope($user, 'p', $eventId);
    $rows = q("SELECT rp.stance AS v, COUNT(*) AS total, COALESCE(SUM(f.all_came), 0) AS came FROM (
                   SELECT p.firm_key, MIN(p.id) AS rep_id, MIN(p.attended_at IS NOT NULL) AS all_came
                   FROM people p WHERE $where GROUP BY p.firm_key
               ) f JOIN people rp ON rp.id = f.rep_id
               GROUP BY rp.stance", $params)->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $out[$r['v'] ?? NONE_VALUE] = ['total' => (int) $r['total'], 'came' => (int) $r['came']];
    }
    return $out;
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
    // FİRMA bazında: total = o bölge/gruptaki firma sayısı, came = şubeleri (o bölge/grupta) hep gelen firma.
    // Bir firma birden çok bölgede şube açabilir → her bölgede ayrı sayılır (o bölge filtresinde kartta görünür).
    $rows = q(
        "SELECT v, COUNT(*) AS total, COALESCE(SUM(all_came), 0) AS came FROM (
            SELECT NULLIF(p.$column, '') AS v, p.firm_key, MIN(p.attended_at IS NOT NULL) AS all_came
            FROM people p WHERE $where GROUP BY v, p.firm_key
         ) t GROUP BY v ORDER BY v IS NULL, v",
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
    $options = (array) config('stance_options', []);
    // Eski config.php ve kayıt anahtarını koru; tüm ekranlarda yeni etiket/renk kullanılır.
    if (isset($options['karsi'])) $options['karsi'] = ['Rakip', 'danger'];
    return $options;
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
 * Transaction İÇİNDE çağrılır: kişinin firma grubunun kapsamdaki TÜM satırlarını kilitler
 * (sadece değişecekleri değil). Böylece aynı gruba eşzamanlı GELDİ / Geri al / Bizimle mi?
 * istekleri sıraya girer, grup yarım kalmaz. Kişi kapsam dışıysa null.
 * @return ?list<array{id:int, attended_at:?string, attended_by:?int, stance:?string, in_window:int}>
 */
function lock_group(array $user, int $personId): ?array
{
    [$scope, $params] = people_scope($user);
    $key = q("SELECT p.firm_key FROM people p WHERE p.id = ? AND $scope", [$personId, ...$params])->fetchColumn();
    if ($key === false) {
        return null;
    }
    $window = (int) config('unmark_window_minutes', 5);
    $rows = q(
        "SELECT p.id, p.attended_at, p.attended_by, p.stance,
                (p.attended_at >= UTC_TIMESTAMP() - INTERVAL $window MINUTE) AS in_window
         FROM people p WHERE p.firm_key = ? AND $scope ORDER BY p.id FOR UPDATE",
        [$key, ...$params]
    )->fetchAll();
    return $rows ?: null;
}

/**
 * Kişinin firma grubunun (kapsamdaki tüm kayıtlarının) "Bizimle mi?" değerini değiştirir.
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
    // Sıra: önce liste kilidi, sonra transaction + satır kilitleri (sync_stance_supporters ile aynı sıra)
    $found = with_supporter_lock($event['id'], function () use ($user, $personId, $value, $event): bool {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $members = lock_group($user, $personId); // okuma transaction içinde, kilitli
            if ($members === null) {
                $pdo->rollBack();
                return false;
            }
            foreach ($members as $m) {
                if ($m['stance'] === $value) {
                    continue;
                }
                q('UPDATE people SET stance = ? WHERE id = ?', [$value, $m['id']]);
                q(
                    'INSERT INTO attendance_log (event_id, person_id, user_id, action, note, ip, created_at) VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())',
                    [$event['id'], $m['id'], $user['id'], 'stance', $value, client_ip_bin()]
                );
            }
            sync_supporter_for_group($event['id'], array_map('intval', array_column($members, 'id')), $value, $user['id']);
            $pdo->commit();
            return true;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    });
    if (!$found) {
        return ['ok' => false, 'status' => 404, 'error' => 'Kişi bulunamadı.'];
    }
    return ['ok' => true, 'status' => 200, 'person' => group_view(find_group($user, $personId), $user)];
}

/* ---------- "Bizimle" etiketi ↔ Bizimkiler listesi ---------- */

/** Kilit zamanında alınamadı: kullanıcıya "meşgul, tekrar dene" gösterilir (500 değil). */
final class BusyException extends RuntimeException
{
}

/**
 * Etiket↔liste senkronunu etkinlik başına SIRAYA sokar (MySQL adlı kilit). Paralel iki istek
 * aynı firma için iki "elle" kayıt açamaz. Satır kilitlerinden ÖNCE alınmalı (deadlock olmasın).
 */
function with_supporter_lock(int $eventId, callable $fn): mixed
{
    $name = 'secim_supporters_' . $eventId;
    if ((int) q('SELECT GET_LOCK(?, 10)', [$name])->fetchColumn() !== 1) {
        throw new BusyException('Sistem meşgul, birkaç saniye sonra tekrar deneyin.');
    }
    try {
        return $fn();
    } finally {
        q('SELECT RELEASE_LOCK(?)', [$name]);
    }
}

/** Bizimkiler'de sayılan "Bizimle mi?" anahtarı (config'de yoksa null). */
function supporter_stance(): ?string
{
    $key = (string) config('supporter_stance', 'bizimle');
    return isset(stance_options()[$key]) ? $key : null;
}

/**
 * Etiket ile liste tek gerçek: "Bizimle" olan her firma grubu Bizimkiler'de sayılır.
 *  - Bizimle yapıldı, hiçbir onaylı Bizimkiler kaydına bağlı değil → "elle" kaynaklı kayıt açılır.
 *  - Bizimle'den çıkarıldı → bağlar kopar; bağı kalmayan elle kayıt silinir, liste kaydı "Bulunamadı"ya döner.
 * Çağıranın transaction'ı içinde çalışır.
 */
function sync_supporter_for_group(int $eventId, array $ids, ?string $value, ?int $userId): void
{
    $bizimle = supporter_stance();
    if ($bizimle === null || !$ids) {
        return;
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    if ($value === $bizimle) {
        // Kişi zaten Bizimkiler'de HERHANGİ bir kayda bağlıysa (yüklenen liste: matched/suggested/none
        // ya da elle) ikinci bir "elle" kayıt AÇMA — aynı kişi iki kez görünmesin.
        $linked = (int) q("SELECT COUNT(*) FROM supporter_people WHERE person_id IN ($in)", $ids)->fetchColumn();
        if ($linked) {
            return;
        }
        $p = q('SELECT full_name, authority FROM people WHERE id = ?', [$ids[0]])->fetch();
        $name = trim(explode(',', (string) $p['authority'])[0]) ?: $p['full_name'];
        // Aynı isim Bizimkiler'de zaten varsa (örn. bağı kopmuş 'none' liste kaydı) ikinci kayıt açma
        if ((int) q('SELECT COUNT(*) FROM supporters WHERE event_id = ? AND search_key = ?', [$eventId, search_key($name)])->fetchColumn()) {
            return;
        }
        q("INSERT INTO supporters (event_id, name, search_key, status, source, created_by, created_at)
           VALUES (?, ?, ?, 'matched', 'manual', ?, UTC_TIMESTAMP())", [$eventId, $name, search_key($name), $userId]);
        $sid = (int) db()->lastInsertId();
        foreach ($ids as $id) {
            q('INSERT IGNORE INTO supporter_people (supporter_id, person_id) VALUES (?, ?)', [$sid, $id]);
        }
        return;
    }
    // "Bizimle"den çıkarıldı: SADECE elle (manual) kayıtları etkiler. Yüklenen liste (source='list')
    // SABİT kalır — onay ekranındaki etiket değişimi listeyi bozmaz / "kontrol bekleyen"e DÜŞÜRMEZ.
    $manualSids = q("SELECT DISTINCT sp.supporter_id FROM supporter_people sp JOIN supporters s ON s.id = sp.supporter_id
                     WHERE sp.person_id IN ($in) AND s.source = 'manual'", $ids)->fetchAll(PDO::FETCH_COLUMN);
    if (!$manualSids) {
        return;
    }
    q("DELETE sp FROM supporter_people sp JOIN supporters s ON s.id = sp.supporter_id
       WHERE sp.person_id IN ($in) AND s.source = 'manual'", $ids);
    foreach ($manualSids as $sid) {
        if ((int) q('SELECT COUNT(*) FROM supporter_people WHERE supporter_id = ?', [$sid])->fetchColumn() === 0) {
            q("DELETE FROM supporters WHERE id = ? AND source = 'manual'", [$sid]);
        }
    }
}

/**
 * Var olan "Bizimle" etiketlerini (elle verilmiş, ya da Excel'in stance sütunundan gelmiş)
 * Bizimkiler'e taşır. Tekrar çalıştırmak güvenli. @return açılan kayıt sayısı
 */
function sync_stance_supporters(int $eventId, ?int $userId): int
{
    $bizimle = supporter_stance();
    if ($bizimle === null) {
        return 0;
    }
    // Kilit + transaction: aynı anda açılan iki sayfa aynı firmaya iki kayıt açamaz
    return with_supporter_lock($eventId, function () use ($eventId, $bizimle, $userId): int {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            // Bizimle olup Bizimkiler'de HİÇ kaydı olmayan firmalar (elle etiketlenmiş, listede yok):
            // bunlara elle kayıt açılır. Listede zaten olan (matched/suggested/none) kişi mükerrer açılmaz.
            $keys = q("SELECT DISTINCT p.firm_key FROM people p WHERE p.event_id = ? AND p.stance = ?
                       AND NOT EXISTS (SELECT 1 FROM supporter_people sp WHERE sp.person_id = p.id)", [$eventId, $bizimle])->fetchAll(PDO::FETCH_COLUMN);
            foreach ($keys as $key) {
                $ids = array_map('intval', q('SELECT id FROM people WHERE event_id = ? AND firm_key = ? AND stance = ?',
                    [$eventId, $key, $bizimle])->fetchAll(PDO::FETCH_COLUMN));
                sync_supporter_for_group($eventId, $ids, $bizimle, $userId);
            }
            $pdo->commit();
            return count($keys);
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    });
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

/**
 * GELDİ: kişinin firma grubunda, kullanıcının kapsamındaki henüz işaretlenmemiş TÜM kayıtlar.
 * Yarış güvenli: satırlar FOR UPDATE ile kilitlenir, koşullu UPDATE + log tek transaction.
 * @return array{ok:bool, status:int, error?:string, person?:array}
 */
function mark_person(array $user, int $personId): array
{
    if (!can('mark', $user)) {
        return ['ok' => false, 'status' => 403, 'error' => 'Yetkiniz yok.'];
    }
    $event = active_event();
    if ($event === null) {
        return ['ok' => false, 'status' => 409, 'error' => 'Aktif etkinlik yok.'];
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $rows = lock_group($user, $personId);
        if ($rows === null) {
            $pdo->rollBack();
            return ['ok' => false, 'status' => 404, 'error' => 'Kişi bulunamadı.'];
        }
        $ids = array_column(array_filter($rows, fn($r) => $r['attended_at'] === null), 'id');
        $done = 0;
        foreach ($ids as $id) {
            $st = q('UPDATE people SET attended_at = UTC_TIMESTAMP(), attended_by = ? WHERE id = ? AND attended_at IS NULL', [$user['id'], $id]);
            if ($st->rowCount() === 1) {
                q('INSERT INTO attendance_log (event_id, person_id, user_id, action, ip, created_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())',
                    [$event['id'], $id, $user['id'], 'mark', client_ip_bin()]);
                $done++;
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    $view = group_view(find_group($user, $personId), $user);
    if ($done > 0) {
        return ['ok' => true, 'status' => 200, 'person' => $view, 'marked' => $done];
    }
    return [
        'ok' => false, 'status' => 409, 'person' => $view,
        'error' => 'Zaten işaretli: ' . ($view['attended_by_name'] ?? '?') . ' — ' . $view['attended_time'],
    ];
}

/**
 * Geri al: grubun, kullanıcının geri alabileceği işaretli kayıtları
 * (yönetici: hepsi; onaycı: sadece kendi işaretledikleri ve süre içinde).
 * @return array{ok:bool, status:int, error?:string, person?:array}
 */
function unmark_person(array $user, int $personId): array
{
    if (!can('mark', $user)) {
        return ['ok' => false, 'status' => 403, 'error' => 'Yetkiniz yok.'];
    }
    $event = active_event();
    if ($event === null) {
        return ['ok' => false, 'status' => 409, 'error' => 'Aktif etkinlik yok.'];
    }
    $any = can('unmark_any', $user);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $rows = lock_group($user, $personId);
        if ($rows === null) {
            $pdo->rollBack();
            return ['ok' => false, 'status' => 404, 'error' => 'Kişi bulunamadı.'];
        }
        // yönetici: tüm işaretliler; onaycı: kendi işaretledikleri ve süre içinde
        $ids = array_column(array_filter($rows, fn($r) => $r['attended_at'] !== null
            && ($any || ((int) $r['attended_by'] === $user['id'] && (int) $r['in_window'] === 1))), 'id');
        foreach ($ids as $id) {
            q('UPDATE people SET attended_at = NULL, attended_by = NULL WHERE id = ?', [$id]);
            q('INSERT INTO attendance_log (event_id, person_id, user_id, action, ip, created_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())',
                [$event['id'], $id, $user['id'], 'unmark', client_ip_bin()]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    $view = group_view(find_group($user, $personId), $user);
    if ($ids) {
        return ['ok' => true, 'status' => 200, 'person' => $view];
    }
    if ($view['came_count'] === 0) {
        return ['ok' => false, 'status' => 409, 'person' => $view, 'error' => 'Bu kişi zaten işaretsiz.'];
    }
    $min = (int) config('unmark_window_minutes', 5);
    return [
        'ok' => false, 'status' => 403, 'person' => $view,
        'error' => "Sadece kendi işaretinizi $min dakika içinde geri alabilirsiniz. Yöneticiye başvurun.",
    ];
}

/* ---------- Elle kişi ekleme (yönetici) ---------- */

/**
 * Aktif listeye tek kişi ekler. $in: name (zorunlu, kişinin adı), firm, ref_no, phone, region, group.
 * Firma yazılırsa kartta ad = kişi (yetkili), altında firma; yazılmazsa kişi adı unvan olur.
 * Sicil no boşsa config('placeholder_ref') yazılır. Aynı isim / aynı sicil varsa $force olmadan eklemez.
 * @return array{ok:bool, status:int, error?:string, similar?:list<string>, person?:array, id?:int}
 */
function add_person(array $user, array $in, bool $force = false): array
{
    if (!can('add_person', $user)) {
        return ['ok' => false, 'status' => 403, 'error' => 'Yetkiniz yok.'];
    }
    $event = active_event();
    if ($event === null) {
        return ['ok' => false, 'status' => 409, 'error' => 'Aktif liste yok, önce liste yükleyin.'];
    }
    $placeholder = (string) config('placeholder_ref', '11111111');
    $name = clean_text($in['name'] ?? null, 160);
    $firm = clean_text($in['firm'] ?? null, 160);
    $ref = clean_text($in['ref_no'] ?? null, 64);
    $phoneRaw = clean_text($in['phone'] ?? null, 40);
    $phone = normalize_phone($phoneRaw);
    $problem = match (true) {
        $name === null => 'Ad soyad zorunlu.',
        $ref !== null && !preg_match('/^[\p{L}\p{N}\/._-]{1,64}$/u', $ref) => 'Sicil no sadece harf, rakam ve / . _ - içerebilir.',
        $phoneRaw !== null && $phone === null => 'Telefon numarası geçersiz. Örnek: 0532 123 45 67',
        default => null,
    };
    if ($problem !== null) {
        return ['ok' => false, 'status' => 422, 'error' => $problem];
    }
    $ref ??= $placeholder;

    if (!$force) {
        // Aynı kişi (yetkili adı ya da unvanı birebir) ya da aynı gerçek sicil listede var mı?
        [$scope, $params] = people_scope($user);
        $key = search_key($name);
        $words = array_slice(array_filter(explode(' ', $key)), 0, 6);
        $sql = implode(' AND ', array_fill(0, count($words), 'p.search_key LIKE ?')) ?: '1 = 0';
        $p = [...$params, ...array_map(fn($w) => '%' . like_escape($w) . '%', $words)];
        if ($ref !== $placeholder) {
            $sql = "($sql) OR p.ref_no = ?";
            $p[] = $ref;
        }
        $similar = [];
        foreach (q("SELECT p.full_name, p.authority, p.ref_no FROM people p WHERE $scope AND ($sql) LIMIT 200", $p)->fetchAll() as $r) {
            $names = array_map('search_key', [$r['full_name'], ...explode(',', (string) $r['authority'])]);
            if (in_array($key, $names, true) || ($ref !== $placeholder && $r['ref_no'] === $ref)) {
                $similar[] = $r['authority'] ? $r['authority'] . ' (' . $r['full_name'] . ')' : $r['full_name'];
            }
        }
        if ($similar) {
            $similar = array_slice(array_values(array_unique($similar)), 0, 5);
            return ['ok' => false, 'status' => 409, 'similar' => $similar,
                    'error' => 'Listede aynı kayıt var: ' . implode('; ', $similar) . '. Yine de eklensin mi?'];
        }
    }

    $id = insert_person($event['id'], null, [
        'full_name'  => $firm ?? $name,
        'authority'  => $firm !== null ? $name : null,
        'ref_no'     => $ref,
        'phone'      => $phone,
        'region'     => $in['region'] ?? null,
        'group_name' => $in['group'] ?? null,
        'extra'      => ['Kaynak' => 'Elle eklendi', 'Ekleyen' => $user['full_name']],
    ]);
    audit('person_create', $name, array_filter([
        'firma' => $firm, 'sicil' => $ref, 'telefon' => $phone !== null ? 'var' : null,
        'ilçe' => clean_text($in['region'] ?? null, 120), 'grup' => clean_text($in['group'] ?? null, 120),
        'aynı kayıt uyarısı geçildi' => $force ? 'evet' : null,
    ]), $user['id']);
    return ['ok' => true, 'status' => 200, 'id' => $id, 'person' => group_view(find_group($user, $id), $user)];
}
