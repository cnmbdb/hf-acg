<?php
declare(strict_types=1);

namespace App\Plugin\ContentCenter\Service;

use Kernel\Exception\JSONException;

final class BlogDetailSettings
{
    public static function defaults(): array
    {
        return [
            'sidebar_enabled' => true,
            'more_articles_limit' => 5,
        ];
    }

    public static function fromInput(mixed $raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                throw new JSONException('文章详情页设置格式无效');
            }
            $raw = $decoded;
        }
        if (!is_array($raw)) {
            $raw = [];
        }

        $defaults = self::defaults();
        return [
            'sidebar_enabled' => filter_var(
                $raw['sidebar_enabled'] ?? $defaults['sidebar_enabled'],
                FILTER_VALIDATE_BOOL
            ),
            'more_articles_limit' => min(
                8,
                max(3, (int)($raw['more_articles_limit'] ?? $defaults['more_articles_limit']))
            ),
        ];
    }

    public static function effective(mixed $raw): array
    {
        try {
            return self::fromInput($raw);
        } catch (\Throwable) {
            return self::defaults();
        }
    }
}
