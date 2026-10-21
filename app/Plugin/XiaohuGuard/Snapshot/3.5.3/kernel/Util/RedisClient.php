<?php
declare(strict_types=1);

namespace Kernel\Util;

final class RedisClient
{
    /** @var array<int, \Redis|null> */
    private static array $connections = [];

    public static function enabled(): bool
    {
        return (bool)(config('redis')['enabled'] ?? false) && extension_loaded('redis');
    }

    public static function connection(?int $database = null): ?\Redis
    {
        if (!self::enabled()) {
            return null;
        }

        $config = config('redis');
        $database ??= (int)($config['cache_database'] ?? 2);
        if (array_key_exists($database, self::$connections)) {
            return self::$connections[$database];
        }

        try {
            $redis = new \Redis();
            $connected = $redis->connect(
                (string)($config['host'] ?? '127.0.0.1'),
                (int)($config['port'] ?? 6379),
                (float)($config['timeout'] ?? 1.5)
            );
            if (!$connected) {
                return self::$connections[$database] = null;
            }

            $username = (string)($config['username'] ?? '');
            $password = (string)($config['password'] ?? '');
            if ($password !== '') {
                $credentials = $username === '' ? $password : [$username, $password];
                if (!$redis->auth($credentials)) {
                    return self::$connections[$database] = null;
                }
            }

            if (!$redis->select($database) || $redis->ping() !== true) {
                return self::$connections[$database] = null;
            }

            return self::$connections[$database] = $redis;
        } catch (\Throwable) {
            return self::$connections[$database] = null;
        }
    }

    public static function configureSession(): bool
    {
        $config = config('redis');
        $database = (int)($config['session_database'] ?? 1);
        if (self::connection($database) === null) {
            return false;
        }

        $query = [
            'database=' . $database,
            'prefix=' . rawurlencode((string)($config['session_prefix'] ?? 'acg_sess:')),
            'timeout=' . (float)($config['timeout'] ?? 1.5),
            'read_timeout=' . (float)($config['timeout'] ?? 1.5),
        ];
        $username = (string)($config['username'] ?? '');
        $password = (string)($config['password'] ?? '');
        if ($password !== '') {
            if ($username !== '') {
                $query[] = 'auth[]=' . rawurlencode($username);
                $query[] = 'auth[]=' . rawurlencode($password);
            } else {
                $query[] = 'auth=' . rawurlencode($password);
            }
        }

        $path = sprintf(
            'tcp://%s:%d?%s',
            (string)($config['host'] ?? '127.0.0.1'),
            (int)($config['port'] ?? 6379),
            implode('&', $query)
        );

        ini_set('session.save_handler', 'redis');
        ini_set('session.save_path', $path);
        ini_set('redis.session.locking_enabled', '1');
        ini_set('redis.session.lock_retries', '20');
        ini_set('redis.session.lock_wait_time', '2000');
        return ini_get('session.save_handler') === 'redis';
    }
}
