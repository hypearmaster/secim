<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

// Yeni kişi ekle (yönetici/admin). POST name, firm, ref_no, phone, region, group, force=1, _csrf / X-CSRF-Token
// Yanıt: 200 {ok, person}; 409 {ok:false, error, similar} aynı kayıt var (force=1 ile tekrar gönderilir); 422 hata
$user = require_perm('add_person');
if (!is_post()) {
    json_response(['ok' => false, 'error' => 'Sadece POST.'], 405);
}
csrf_verify();

$in = [];
foreach (['name', 'firm', 'ref_no', 'phone', 'region', 'group'] as $k) {
    $in[$k] = is_string($_POST[$k] ?? null) ? $_POST[$k] : null;
}
$result = add_person($user, $in, ($_POST['force'] ?? '') === '1');
$status = $result['status'];
unset($result['status'], $result['id']);
if ($result['ok']) {
    $result['stats'] = scope_stats($user);
}
json_response($result, $status);
