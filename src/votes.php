<?php
declare(strict_types=1);

/*
 * Oy sayacı: büyük mavi/kırmızı (ileride beyaz/yeşil) butonlar. Her tıklama vote_log'a bir satır;
 * sayımlar COUNT(*) ile. Geri alma = o seçeneğin en son tıklamasını siler. Hepsi kim/ne zaman loglanır.
 * Renkler config('vote_options')'tan gelir, sayfa kendiliğinden büyür.
 */

/** @return array<string, array{label:string, color:string}> anahtar => etiket + renk(hex) */
function vote_options(): array
{
    $out = [];
    foreach ((array) config('vote_options', []) as $key => $opt) {
        if (is_string($key) && is_array($opt) && isset($opt[0], $opt[1])) {
            $out[$key] = ['label' => (string) $opt[0], 'color' => (string) $opt[1]];
        }
    }
    return $out;
}

/**
 * Sayaç durumu: her seçenek için sayı + toplam + son tıklama (geri al için).
 * @return array{options:list<array{key:string,label:string,color:string,count:int}>, total:int,
 *               last:?array{key:string,label:string,user:string,time:string}}
 */
function vote_tallies(int $eventId): array
{
    $counts = [];
    foreach (q('SELECT option_key, COUNT(*) AS n FROM vote_log WHERE event_id = ? GROUP BY option_key', [$eventId])->fetchAll() as $r) {
        $counts[$r['option_key']] = (int) $r['n'];
    }
    $options = [];
    $total = 0;
    foreach (vote_options() as $key => $opt) {
        $n = $counts[$key] ?? 0;
        $total += $n;
        $options[] = ['key' => $key, 'label' => $opt['label'], 'color' => $opt['color'], 'count' => $n];
    }
    // config'den silinmiş ama kaydı olan seçenekler de toplamda görünsün (veri kaybolmasın)
    foreach ($counts as $key => $n) {
        if (!isset(vote_options()[$key])) {
            $total += $n;
            $options[] = ['key' => $key, 'label' => $key, 'color' => '#6b7280', 'count' => $n];
        }
    }
    $last = q('SELECT v.option_key, v.created_at, u.full_name FROM vote_log v
               LEFT JOIN users u ON u.id = v.user_id WHERE v.event_id = ? ORDER BY v.id DESC LIMIT 1', [$eventId])->fetch();
    return [
        'options' => $options,
        'total'   => $total,
        'last'    => $last ? [
            'key'  => $last['option_key'],
            'label' => vote_options()[$last['option_key']]['label'] ?? $last['option_key'],
            'user' => $last['full_name'] ?? '',
            'time' => fmt_time($last['created_at']),
        ] : null,
    ];
}

/**
 * Bir seçeneğe +1. @return array{ok:bool, status:int, error?:string, tallies?:array}
 */
function vote_add(array $user, string $optionKey): array
{
    if (!can('vote', $user)) {
        return ['ok' => false, 'status' => 403, 'error' => 'Yetkiniz yok.'];
    }
    if (!isset(vote_options()[$optionKey])) {
        return ['ok' => false, 'status' => 422, 'error' => 'Geçersiz seçenek.'];
    }
    $event = active_event();
    if ($event === null) {
        return ['ok' => false, 'status' => 409, 'error' => 'Aktif etkinlik yok.'];
    }
    q('INSERT INTO vote_log (event_id, option_key, user_id, ip, created_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP())',
        [$event['id'], $optionKey, $user['id'], client_ip_bin()]);
    return ['ok' => true, 'status' => 200, 'tallies' => vote_tallies($event['id'])];
}

/**
 * Geri al: belirtilen seçeneğin (yoksa en son tıklananın) EN SON tıklamasını siler.
 * Eşzamanlı iki geri al: biri satırı siler, öteki "zaten geri alınmış" döner (çift düşmez).
 * @return array{ok:bool, status:int, error?:string, tallies?:array, undone?:string}
 */
function vote_undo(array $user, ?string $optionKey = null): array
{
    if (!can('vote', $user)) {
        return ['ok' => false, 'status' => 403, 'error' => 'Yetkiniz yok.'];
    }
    $event = active_event();
    if ($event === null) {
        return ['ok' => false, 'status' => 409, 'error' => 'Aktif etkinlik yok.'];
    }
    if ($optionKey !== null && !isset(vote_options()[$optionKey])) {
        return ['ok' => false, 'status' => 422, 'error' => 'Geçersiz seçenek.'];
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $sql = 'SELECT id, option_key FROM vote_log WHERE event_id = ?';
        $params = [$event['id']];
        if ($optionKey !== null) {
            $sql .= ' AND option_key = ?';
            $params[] = $optionKey;
        }
        $sql .= ' ORDER BY id DESC LIMIT 1 FOR UPDATE';
        $row = q($sql, $params)->fetch();
        if (!$row) {
            $pdo->commit();
            return ['ok' => false, 'status' => 409, 'tallies' => vote_tallies($event['id']),
                    'error' => 'Geri alınacak kayıt yok.'];
        }
        q('DELETE FROM vote_log WHERE id = ?', [$row['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return ['ok' => true, 'status' => 200, 'tallies' => vote_tallies($event['id']),
            'undone' => vote_options()[$row['option_key']]['label'] ?? $row['option_key']];
}

/** Sayacı sıfırlar (sadece admin). Tüm tıklamaları siler; denetime yazılır. */
function vote_reset(array $user): array
{
    if (!can('vote_reset', $user)) {
        return ['ok' => false, 'status' => 403, 'error' => 'Yetkiniz yok.'];
    }
    $event = active_event();
    if ($event === null) {
        return ['ok' => false, 'status' => 409, 'error' => 'Aktif etkinlik yok.'];
    }
    $before = vote_tallies($event['id']);
    q('DELETE FROM vote_log WHERE event_id = ?', [$event['id']]);
    audit('vote_reset', $event['name'], ['sıfırlanan toplam' => $before['total']]
        + array_column(array_map(fn($o) => [$o['label'], $o['count']], $before['options']), 1, 0), $user['id']);
    return ['ok' => true, 'status' => 200, 'tallies' => vote_tallies($event['id'])];
}
