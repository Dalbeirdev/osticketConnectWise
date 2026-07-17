<?php

declare(strict_types=1);

/**
 * Front controller. All requests route through here (Apache .htaccess or the
 * PHP built-in server: php -S 127.0.0.1:8085 -t public public/index.php).
 */

// Built-in server: serve real files (assets) directly.
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    if (is_file($file)) {
        return false;
    }
}

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Dependencies not installed. Run: composer install\n";
    exit(1);
}
require $autoload;

$app = new App\Core\App(dirname(__DIR__));
$app->run();
