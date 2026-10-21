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
    'enabled' => filter_var($env('REDIS_ENABLED', 'false'), FILTER_VALIDATE_BOOL),
    'host' => $env('REDIS_HOST', '127.0.0.1'),
    'port' => (int)$env('REDIS_PORT', '6379'),
    'username' => $env('REDIS_USERNAME'),
    'password' => $env('REDIS_PASSWORD'),
    'timeout' => (float)$env('REDIS_TIMEOUT', '1.5'),
    'session_database' => (int)$env('REDIS_SESSION_DB', '1'),
    'cache_database' => (int)$env('REDIS_CACHE_DB', '2'),
    'session_prefix' => $env('REDIS_SESSION_PREFIX', 'acg_sess:'),
    'cache_prefix' => $env('REDIS_CACHE_PREFIX', 'acg_cache:'),
];
