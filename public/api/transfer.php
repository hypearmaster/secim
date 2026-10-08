<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

// POST person_id, value=transfer ya da boş / "kendisi" (Kendisi gelecek), _csrf / X-CSRF-Token
$user = require_perm('transfer');
if (!is_post()) {
    json_response(['ok' => false, 'error' => 'Sadece POST.'], 405);
}
csrf_verify();

$personId = (int) ($_POST['person_id'] ?? 0);
$value = is_string($_POST['value'] ?? null) ? $_POST['value'] : '';
if ($personId <= 0) {
    json_response(['ok' => false, 'error' => 'Geçersiz istek.'], 422);
}

$result = set_transfer($user, $personId, $value === '' ? null : $value);
$status = $result['status'];
unset($result['status']);
json_response($result, $status);
