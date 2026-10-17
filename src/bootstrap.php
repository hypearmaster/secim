<?php
declare(strict_types=1);

// Her sayfanın ilk satırı: require __DIR__ . '/../src/bootstrap.php';  (admin/ ve api/ için ../../src)

define('APP_ROOT', dirname(__DIR__));

$configFile = APP_ROOT . '/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    exit('config.php bulunamadı — config.example.php dosyasını kopyalayıp doldurun.');
}
$GLOBALS['__config'] = require $configFile;

$debug = (bool) ($GLOBALS['__config']['debug'] ?? false);
ini_set('display_errors', $debug ? '1' : '0');
error_reporting(E_ALL);

// Saklama UTC; gösterim fmt_time()/fmt_datetime() ile yerel saate çevrilir.
date_default_timezone_set('UTC');

if (PHP_SAPI !== 'cli') {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
}

require APP_ROOT . '/vendor/autoload.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/audit.php';
require __DIR__ . '/people.php';
require __DIR__ . '/team.php';
require __DIR__ . '/layout.php';

if (PHP_SAPI !== 'cli') {
    start_session();
}
