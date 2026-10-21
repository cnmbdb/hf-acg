<?php
declare(strict_types=1);

namespace Kernel\Cache;

use Kernel\Exception\RuntimeException;
use Kernel\Util\File;
use Kernel\Util\RedisClient;

class Cache
{

    private string $cacheDir;
    private int $resolve;
    private ?\Redis $redis;
    private string $redisKeyPrefix;

    public const OPTIONS_SERIALIZE = 0;
    public const OPTIONS_JSON = 1;
    public const OPTIONS_STRING = 2;

    public function __construct(string $path, int $resolve = self::OPTIONS_SERIALIZE)
    {
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }

        $this->cacheDir = rtrim($path, "/") . "/";
        $this->resolve = $resolve;
        $redisConfig = config('redis');
        $this->redis = RedisClient::connection((int)($redisConfig['cache_database'] ?? 2));
        $namespace = str_replace('\\', '/', str_replace(BASE_PATH, '', $this->cacheDir));
        $this->redisKeyPrefix = (string)($redisConfig['cache_prefix'] ?? 'acg_cache:')
            . sha1($namespace) . ':';
    }


    /**
     * @param string $name
     * @param mixed $value
     * @return void
     * @throws RuntimeException
     */
    public function set(string $name, mixed $value): void
    {
        $encoded = $this->encode($value);
        if ($this->redis !== null) {
            try {
                if ($this->redis->set($this->redisKey($name), $encoded)) {
                    return;
                }
            } catch (\Throwable) {
                $this->redis = null;
            }
        }

        $hashFile = $this->cacheDir . md5($name);
        File::writeForLock($hashFile, function (string $contents) use ($encoded) {
            return $encoded;
        });
    }

    /**
     * @param string $name
     * @return bool
     */
    public function has(string $name): bool
    {
        if ($this->redis !== null) {
            try {
                return $this->redis->exists($this->redisKey($name)) > 0;
            } catch (\Throwable) {
                $this->redis = null;
            }
        }

        return file_exists($this->cacheDir . md5($name));
    }


    /**
     * @param string $name
     * @return void
     */
    public function del(string $name): void
    {
        if ($this->redis !== null) {
            try {
                $this->redis->del($this->redisKey($name));
            } catch (\Throwable) {
                $this->redis = null;
            }
        }

        $file = $this->cacheDir . md5($name);
        if (file_exists($file)) {
            unlink($file);
        }
    }

    /**
     * @param string $name
     * @return mixed
     */
    public function get(string $name): mixed
    {
        if ($this->redis !== null) {
            try {
                $contents = $this->redis->get($this->redisKey($name));
                return $contents === false ? null : $this->decode((string)$contents);
            } catch (\Throwable) {
                $this->redis = null;
            }
        }

        return File::read($this->cacheDir . md5($name), function (string $contents) {
            return $this->decode($contents);
        });
    }

    private function redisKey(string $name): string
    {
        return $this->redisKeyPrefix . md5($name);
    }

    private function encode(mixed $value): string
    {
        return match ($this->resolve) {
            self::OPTIONS_SERIALIZE => base64_encode(serialize($value)),
            self::OPTIONS_JSON => base64_encode((string)json_encode($value)),
            default => base64_encode((string)$value),
        };
    }

    private function decode(string $contents): mixed
    {
        return match ($this->resolve) {
            self::OPTIONS_SERIALIZE => unserialize((string)base64_decode($contents, true)),
            self::OPTIONS_JSON => json_decode((string)base64_decode($contents, true)),
            default => base64_decode($contents, true),
        };
    }
}
