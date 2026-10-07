<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

// GET ?q=&region=&group=&status=all|came|pending&stance=<anahtar|__none__>&page=1  (stance sadece yetkili)
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

$result = search_people($user, $filters, $perPage, ($page - 1) * $perPage);

json_response([
    'ok'       => true,
    'items'    => $result['items'],
    'total'    => $result['total'],
    'page'     => $page,
    'has_more' => $page * $perPage < $result['total'],
    'stats'    => scope_stats($user),
]);
