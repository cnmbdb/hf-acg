<?php
declare(strict_types=1);

// Development router for PHP's built-in web server. It mirrors .htaccess so
// local development does not require Apache or Docker. Keep cache-busting and
// expanded assets for localhost only; the public Tunnel hostname should use
// stable production assets so browsers and Cloudflare can cache them.
$requestHost = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
$isLocalDevelopment = preg_match('/^(localhost|127\.0\.0\.1|\[::1\])(?::\d+)?$/', $requestHost) === 1;
defined('DEBUG') || define('DEBUG', $isLocalDevelopment);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = __DIR__ . $path;

if ($path !== '/' && is_file($file)) {
    return false;
}

if (preg_match('#^/blog/([a-z0-9]+(?:-[a-z0-9]+)*)/?$#', $path, $match)) {
    $path = '/plugin/ContentCenter/index/article.' . $match[1];
} else {
    $path = match ($path) {
        '/2' => '/plugin/HStockPlus/index/index',
        '/3' => '/user/index/three',
        '/blog', '/blog/' => '/plugin/ContentCenter/index/blog',
        default => $path,
    };
}

if ($path === '/') {
    unset($_GET['s']);
} else {
    $_GET['s'] = $path;
}
require __DIR__ . '/index.php';
