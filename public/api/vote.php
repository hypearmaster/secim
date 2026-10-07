<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';
require APP_ROOT . '/src/votes.php';

// Oy sayacı. GET: güncel durum. POST action=add|undo|reset (+ option), _csrf / X-CSRF-Token
$user = require_perm('vote');
$event = active_event();

if (!is_post()) {
    if ($event === null) {
        json_response(['ok' => true, 'tallies' => ['options' => [], 'total' => 0, 'last' => null], 'event_id' => null, 'no_event' => true]);
    }
    json_response(['ok' => true, 'event_id' => $event['id'], 'tallies' => vote_tallies($event['id'])]);
}

csrf_verify();
$action = $_POST['action'] ?? '';
$option = is_string($_POST['option'] ?? null) && $_POST['option'] !== '' ? $_POST['option'] : null;
// İstemcinin açtığı etkinlik: değiştiyse yazma başka listeye kaymasın
$expected = isset($_POST['event_id']) && $_POST['event_id'] !== '' ? (int) $_POST['event_id'] : null;

$result = match ($action) {
    'add'   => vote_add($user, (string) $option, $expected),
    'undo'  => vote_undo($user, $option, $expected),
    'reset' => vote_reset($user, $expected),
    default => ['ok' => false, 'status' => 422, 'error' => 'Geçersiz işlem.'],
};
$status = $result['status'];
unset($result['status']);
json_response($result, $status);
