<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

if (is_post()) {
    csrf_verify();
    if ($u = current_user()) {
        audit('logout', $u['username']);
    }
    logout();
}
redirect('index.php');
