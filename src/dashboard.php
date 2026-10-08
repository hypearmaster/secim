<?php
declare(strict_types=1);

/*
 * Yönetim paneli verisi — TEK KAYNAK: hem admin/index.php'nin ilk çizimi hem de arka plan yenilemesi
 * (api/dashboard.php) bunu kullanır; sayılar iki yerde ayrı hesaplanıp birbirinden sapmaz.
 */

/** @return array{label:string,total:int,came:int,rate:int} */
function dashboard_row(string $label, int $total, int $came): array
{
    return ['label' => $label, 'total' => $total, 'came' => $came, 'rate' => $total ? (int) round($came / $total * 100) : 0];
}

/**
 * @return array{event_id:int, stats:array{total:int,came:int,pending:int,rate:int}, region:list<array>, group:list<array>,
 *               stance:list<array>, logs:list<array>}
 */
function dashboard_data(array $user, int $eventId): array
{
    $stats = scope_stats($user);
    $stats['pending'] = $stats['total'] - $stats['came'];
    $stats['rate'] = $stats['total'] ? (int) round($stats['came'] / $stats['total'] * 100) : 0;

    $rows = fn(string $col) => array_map(fn($b) => dashboard_row((string) ($b['label'] ?? '—'), $b['total'], $b['came']),
        people_breakdown($user, $col, $eventId));

    $stance = [];
    if (can('stance', $user)) {
        // Onay çipleriyle AYNI mantık (stance_breakdown): firma bazlı, firma başına tek stance, all-came
        $sb = stance_breakdown($user, $eventId);
        $order = [...array_keys(stance_options()), NONE_VALUE];
        foreach ($sb as $key => $v) { // config'de olmayan (silinmiş) değerler de sona eklensin
            if (!in_array($key, $order, true)) {
                $order[] = $key;
            }
        }
        foreach ($order as $key) {
            if (!isset($sb[$key])) {
                continue;
            }
            $sv = stance_view($key === NONE_VALUE ? null : $key);
            $stance[] = dashboard_row($sv['label'], $sb[$key]['total'], $sb[$key]['came']) + ['color' => $sv['color']];
        }
    }

    $bizimle = can('stance', $user) ? (string) config('supporter_stance', 'bizimle') : null;
    $logs = [];
    foreach (q("SELECT al.action, al.created_at, p.full_name AS person_name, p.authority AS person_authority, p.region, p.stance AS person_stance, u.full_name AS user_name
                FROM attendance_log al
                JOIN people p ON p.id = al.person_id
                JOIN users u ON u.id = al.user_id
                WHERE al.event_id = ?
                ORDER BY al.created_at DESC, al.id DESC
                LIMIT 10", [$eventId])->fetchAll() as $l) {
        [$label, $badge] = match ($l['action']) {
            'mark'     => ['Geldi', 'success'],
            'unmark'   => ['Geri alındı', 'danger'],
            'transfer' => ['Transfer değiştirildi', 'warning'],
            default    => ['Durum değiştirildi', 'warning'],
        };
        $logs[] = [
            'time'   => fmt_datetime($l['created_at']),
            'label'  => $label,
            'badge'  => $badge,
            'name'   => $l['person_authority'] ?: $l['person_name'],
            'region' => $l['region'] ?? '—',
            'user'   => $l['user_name'],
            'row'    => $bizimle !== null && $l['person_stance'] === 'karsi' ? 'row-rakip'
                : ($bizimle !== null && $l['person_stance'] === $bizimle ? 'row-bizimle' : ''),
        ];
    }

    return [
        'event_id' => $eventId,
        'stats'    => $stats,
        'region'   => $rows('region'),
        'group'    => $rows('group_name'),
        'stance'   => $stance,
        'logs'     => $logs,
    ];
}
