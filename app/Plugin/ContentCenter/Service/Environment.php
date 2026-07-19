<?php
declare(strict_types=1);

namespace App\Plugin\ContentCenter\Service;

final class Environment
{
    public static function get(string $key, string $default = ''): string
    {
        $runtime = getenv($key);
        if ($runtime !== false) {
            return (string)$runtime;
        }

        $file = BASE_PATH . '/.env';
        if (!is_readable($file)) {
            return $default;
        }

        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$name, $value] = explode('=', $line, 2);
            if (trim($name) === $key) {
                return trim($value, " \t\n\r\0\x0B\"'");
            }
        }

        return $default;
    }
}
