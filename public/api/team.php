<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

// POST person_id, action=add|remove, user_id (ops.: başkasının takımından çıkarmak; team_manage), X-CSRF-Token
$user = require_perm('team');
if (!is_post()) {
    json_response(['ok' => false, 'error' => 'Sadece POST.'], 405);
}
csrf_verify();

$personId = (int) ($_POST['person_id'] ?? 0);
$action = $_POST['action'] ?? '';
$ownerId = (int) ($_POST['user_id'] ?? 0);
if ($personId <= 0 || !in_array($action, ['add', 'remove'], true)) {
    json_response(['ok' => false, 'error' => 'Geçersiz istek.'], 422);
}

try {
    $result = $action === 'add'
        ? team_add($user, $personId)
        : team_remove($user, $personId, $ownerId > 0 ? $ownerId : null);
} catch (BusyException $e) {
    json_response(['ok' => false, 'error' => $e->getMessage()], 503);
}
$status = $result['status'];
unset($result['status']);
json_response($result, $status);
