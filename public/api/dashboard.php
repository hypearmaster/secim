<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';
require APP_ROOT . '/src/dashboard.php';

// GET -> {ok, event_id, stats, region, group, stance, logs}. Panelin arka plan yenilemesi (sayfa yenilenmez).
$user = require_perm('dashboard');
$event = active_event();
if ($event === null) {
    json_response(['ok' => true, 'event_id' => null]);
}
json_response(['ok' => true] + dashboard_data($user, $event['id']));
