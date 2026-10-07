<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

if (is_post()) {
    csrf_verify();
    logout();
}
redirect('index.php');
