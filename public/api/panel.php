<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

// GET -> {ok, event_id, stats, region, pending, came, truncated}. "Panelim"in arka plan yenilemesi: kullanıcının KENDİ takımı.
$user = require_perm('team');
$event = active_event();
if ($event === null) {
    json_response(['ok' => true, 'event_id' => null]);
}
json_response(['ok' => true, 'event_id' => $event['id']] + my_panel_data($user));
