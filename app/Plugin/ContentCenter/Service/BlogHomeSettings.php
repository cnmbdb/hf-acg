<?php
declare(strict_types=1);

namespace App\Plugin\ContentCenter\Service;

use Kernel\Exception\JSONException;

final class BlogHomeSettings
{
    public static function defaults(): array
    {
        return [
            'hero_enabled' => true,
            'ticker_enabled' => true,
            'category_enabled' => true,
            'list_enabled' => true,
            'hero_kicker' => '博客精选',
            'hero_heading' => '把出海经验，整理成可复用的方法',
            'hero_subheading' => '产品更新、实用教程与行业观察，持续沉淀在这里。',
            'hero_interval' => 6500,
            'hero_slides' => [
                [
                    'article_id' => 4,
                    'image' => 'https://images.unsplash.com/photo-1499750310107-5fef28a66643?auto=format&fit=crop&w=1800&q=86',
                ],
                [
                    'article_id' => 5,
                    'image' => 'https://images.unsplash.com/photo-1516321318423-f06f85e504b3?auto=format&fit=crop&w=1800&q=86',
                ],
                [
                    'article_id' => 6,
                    'image' => 'https://images.unsplash.com/photo-1456324504439-367cee3b3c32?auto=format&fit=crop&w=1800&q=86',
                ],
            ],
            'ticker_title' => '最新发布',
            'ticker_duration' => 28,
            'category_title' => '按主题浏览',
            'list_title' => '综合文章',
            'list_limit' => 20,
            'animation_enabled' => true,
        ];
    }

    public static function fromInput(mixed $raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                throw new JSONException('博客首页设置格式无效');
            }
            $raw = $decoded;
        }
        if (!is_array($raw)) {
            $raw = [];
        }

        $defaults = self::defaults();
        $slides = [];
        foreach ((array)($raw['hero_slides'] ?? []) as $slide) {
            if (!is_array($slide)) {
                continue;
            }
            $articleId = max(0, (int)($slide['article_id'] ?? 0));
            $image = self::url($slide['image'] ?? '');
            if ($articleId === 0 && $image === '') {
                continue;
            }
            $slides[] = ['article_id' => $articleId, 'image' => $image];
            if (count($slides) >= 5) {
                break;
            }
        }
        if (!$slides) {
            $slides = $defaults['hero_slides'];
        }

        return [
            'hero_enabled' => self::boolean($raw['hero_enabled'] ?? $defaults['hero_enabled']),
            'ticker_enabled' => self::boolean($raw['ticker_enabled'] ?? $defaults['ticker_enabled']),
            'category_enabled' => self::boolean($raw['category_enabled'] ?? $defaults['category_enabled']),
            'list_enabled' => self::boolean($raw['list_enabled'] ?? $defaults['list_enabled']),
            'hero_kicker' => self::text($raw['hero_kicker'] ?? $defaults['hero_kicker'], 40, '精选区短标题'),
            'hero_heading' => self::text($raw['hero_heading'] ?? $defaults['hero_heading'], 80, '精选区标题'),
            'hero_subheading' => self::text($raw['hero_subheading'] ?? $defaults['hero_subheading'], 180, '精选区说明'),
            'hero_interval' => self::number($raw['hero_interval'] ?? $defaults['hero_interval'], 3000, 15000),
            'hero_slides' => $slides,
            'ticker_title' => self::text($raw['ticker_title'] ?? $defaults['ticker_title'], 40, '轮播区标题'),
            'ticker_duration' => self::number($raw['ticker_duration'] ?? $defaults['ticker_duration'], 12, 120),
            'category_title' => self::text($raw['category_title'] ?? $defaults['category_title'], 40, '分类区标题'),
            'list_title' => self::text($raw['list_title'] ?? $defaults['list_title'], 40, '文章列表标题'),
            'list_limit' => 20,
            'animation_enabled' => self::boolean($raw['animation_enabled'] ?? true),
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

    private static function text(mixed $value, int $limit, string $label): string
    {
        $value = trim(strip_tags((string)$value));
        if ($value === '' || mb_strlen($value) > $limit || str_contains($value, "\0")) {
            throw new JSONException($label . '不能为空且不能超过 ' . $limit . ' 个字符');
        }
        return $value;
    }

    private static function url(mixed $value): string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return '';
        }
        if (strlen($value) > 512 || !preg_match('#^(?:https://|/)[^\s]+$#', $value)) {
            throw new JSONException('轮播图片必须是 HTTPS 地址或本站绝对路径');
        }
        return $value;
    }

    private static function number(mixed $value, int $min, int $max): int
    {
        return min($max, max($min, (int)$value));
    }

    private static function boolean(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOL);
    }
}
