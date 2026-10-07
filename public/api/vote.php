<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';
require APP_ROOT . '/src/votes.php';

// Oy sayacı. GET: güncel durum. POST action=add|undo|reset (+ option), _csrf / X-CSRF-Token
$user = require_perm('vote');
$event = active_event();

if (!is_post()) {
    if ($event === null) {
        json_response(['ok' => true, 'tallies' => ['options' => [], 'total' => 0, 'last' => null], 'no_event' => true]);
    }
    json_response(['ok' => true, 'tallies' => vote_tallies($event['id'])]);
}

csrf_verify();
$action = $_POST['action'] ?? '';
$option = is_string($_POST['option'] ?? null) && $_POST['option'] !== '' ? $_POST['option'] : null;

$result = match ($action) {
    'add'   => vote_add($user, (string) $option),
    'undo'  => vote_undo($user, $option),
    'reset' => vote_reset($user),
    default => ['ok' => false, 'status' => 422, 'error' => 'Geçersiz işlem.'],
};
$status = $result['status'];
unset($result['status']);
json_response($result, $status);
