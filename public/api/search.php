<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

// GET ?q=&region=&group=&status=all|came|pending&stance=<anahtar|__none__>&page=1  (stance sadece yetkili)
// Arka plan yenilemesi: &upto=N (1..5) ilk N sayfayı tek yanıtta verir, &facets=1 filtre çipi sayılarını ekler.
$user = require_perm('mark');

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 40;
$filters = [
    'q'      => mb_substr((string) ($_GET['q'] ?? ''), 0, 100),
    'region' => (string) ($_GET['region'] ?? ''),
    'group'  => (string) ($_GET['group'] ?? ''),
    'stance' => (string) ($_GET['stance'] ?? ''),
    'status' => in_array($_GET['status'] ?? '', ['came', 'pending'], true) ? $_GET['status'] : 'all',
];

$upto = max(0, min(5, (int) ($_GET['upto'] ?? 0)));
if ($upto > 0) {
    $page = 1;
}
$limit = $perPage * max(1, $upto);
$result = search_people($user, $filters, $limit, ($page - 1) * $perPage);

$out = [
    'ok'       => true,
    'event_id' => active_event()['id'] ?? null, // aktif liste değişti mi? (istemci tam yeniler)
    'items'    => $result['items'],
    'total'    => $result['total'],
    'page'     => $page,
    'has_more' => ($page - 1) * $perPage + $limit < $result['total'],
    'stats'    => scope_stats($user),
];
if (($_GET['facets'] ?? '') === '1') {
    $out['facets'] = facet_totals($user);
}
json_response($out);
