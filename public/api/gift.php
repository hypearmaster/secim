<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';
require APP_ROOT . '/src/gifts.php';

// Hediye uçları. gift_mark yetkisi.
//  GET  ?q=&status=all|given|pending&page=1  -> {ok, event_id, items, total, page, has_more, stats}
//       Arka plan yenilemesi: &upto=N (1..5) ilk N sayfayı tek yanıtta verir.
//  POST action=mark|unmark (id) | add (name, note); _csrf / X-CSRF-Token  -> {ok, gift?, stats}
$user = require_perm('gift_mark');

if (!is_post()) {
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $perPage = 40;
    $filters = [
        'q'      => mb_substr((string) ($_GET['q'] ?? ''), 0, 100),
        'status' => in_array($_GET['status'] ?? '', ['given', 'pending'], true) ? $_GET['status'] : 'all',
        'region' => mb_substr((string) ($_GET['region'] ?? ''), 0, 120),
    ];
    $upto = max(0, min(5, (int) ($_GET['upto'] ?? 0)));
    if ($upto > 0) {
        $page = 1;
    }
    $limit = $perPage * max(1, $upto);
    $result = search_gifts($filters, $limit, ($page - 1) * $perPage, $user);
    json_response([
        'ok'       => true,
        'event_id' => active_event()['id'] ?? null, // aktif liste değişti mi? (istemci tam yeniler)
        'items'    => $result['items'],
        'total'    => $result['total'],
        'page'     => $page,
        'has_more' => ($page - 1) * $perPage + $limit < $result['total'],
        'stats'    => gift_stats(),
    ]);
}

csrf_verify();
$action = $_POST['action'] ?? '';
$id = (int) ($_POST['id'] ?? 0);
$name = is_string($_POST['name'] ?? null) ? $_POST['name'] : '';
$note = is_string($_POST['note'] ?? null) && $_POST['note'] !== '' ? $_POST['note'] : null;

$result = match ($action) {
    'mark'   => mark_gift($user, $id, true),
    'unmark' => mark_gift($user, $id, false),
    'add'    => add_gift($user, $name, $note),
    'region' => set_gift_region($user, $id, is_string($_POST['region'] ?? null) ? $_POST['region'] : '', (int) ($_POST['event_id'] ?? 0)),
    default  => ['ok' => false, 'status' => 422, 'error' => 'Geçersiz işlem.'],
};
$status = $result['status'];
unset($result['status']);
$result['stats'] = gift_stats();
json_response($result, $status);
