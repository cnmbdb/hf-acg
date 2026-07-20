<?php
declare(strict_types=1);

// Development router for PHP's built-in web server. It mirrors .htaccess so
// local development does not require Apache or Docker.
defined('DEBUG') || define('DEBUG', true);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = __DIR__ . $path;

if ($path !== '/' && is_file($file)) {
    return false;
}

$path = match ($path) {
    '/2' => '/plugin/HStockPlus/index/index',
    '/3' => '/user/index/three',
    '/blog', '/blog/' => '/plugin/ContentCenter/index/blog',
    default => $path,
};

if ($path === '/') {
    unset($_GET['s']);
} else {
    $_GET['s'] = $path;
}
require __DIR__ . '/index.php';
