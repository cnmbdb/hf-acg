<?php
declare(strict_types=1);

$values = [];
$envFile = dirname(__DIR__) . '/.env';
if (is_readable($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $values[trim($key)] = trim($value, " \t\n\r\0\x0B\"'");
    }
}

$env = static function (string $key, string $default = '') use ($values): string {
    $runtimeValue = getenv($key);
    return $runtimeValue !== false ? $runtimeValue : ($values[$key] ?? $default);
};

return [
    'driver' => $env('DB_CONNECTION', 'mysql'),
    'host' => $env('DB_HOST', '127.0.0.1'),
    'port' => (int)$env('DB_PORT', '3306'),
    'database' => $env('DB_DATABASE', 'demo'),
    'username' => $env('DB_USERNAME', 'demo'),
    'password' => $env('DB_PASSWORD'),
    'charset' => $env('DB_CHARSET', 'utf8mb4'),
    'collation' => $env('DB_COLLATION', 'utf8mb4_unicode_ci'),
    'prefix' => $env('DB_PREFIX', 'acg_'),
];
