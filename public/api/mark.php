<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

// POST person_id, action=mark|unmark, _csrf (ya da X-CSRF-Token başlığı)
$user = require_perm('mark');
if (!is_post()) {
    json_response(['ok' => false, 'error' => 'Sadece POST.'], 405);
}
csrf_verify();

$personId = (int) ($_POST['person_id'] ?? 0);
$action = $_POST['action'] ?? 'mark';
if ($personId <= 0 || !in_array($action, ['mark', 'unmark'], true)) {
    json_response(['ok' => false, 'error' => 'Geçersiz istek.'], 422);
}

$result = $action === 'mark' ? mark_person($user, $personId) : unmark_person($user, $personId);
$status = $result['status'];
unset($result['status']);
$result['stats'] = scope_stats($user);

json_response($result, $status);
