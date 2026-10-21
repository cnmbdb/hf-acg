<?php
declare(strict_types=1);

namespace App\Util;

final class ShopUrl
{
    public const SOCIAL_MEDIA_BASE = '/social-media-account';

    public static function decorateCategoryTree(array $categories, string $source): array
    {
        if ($source !== 'fansgurus') {
            return $categories;
        }

        $usedTopLevelSlugs = [];
        foreach ($categories as &$category) {
            $categoryId = (string)($category['id'] ?? '');
            $slug = self::uniqueSlug(
                self::slug((string)($category['name'] ?? ''), 'category-' . $categoryId),
                $usedTopLevelSlugs
            );
            $category['slug'] = $slug;
            $category['path'] = self::SOCIAL_MEDIA_BASE . '/' . $slug;

            $subcategories = isset($category['subcategories']) && is_array($category['subcategories'])
                ? $category['subcategories']
                : [];
            foreach ($subcategories as $index => &$subcategory) {
                $subcategory['slug'] = $slug . '-' . ($index + 1);
                $subcategory['path'] = $category['path'] . '/' . $subcategory['slug'];
            }
            unset($subcategory);
            $category['subcategories'] = $subcategories;
        }
        unset($category);

        return $categories;
    }

    /**
     * @return array{category_id: int|string, subcategory: string}|null
     */
    public static function resolveCategorySelection(array $categories, array $segments): ?array
    {
        $segments = array_values(array_filter(array_map(
            static fn(mixed $segment): string => strtolower(trim(rawurldecode((string)$segment), '/')),
            $segments
        ), static fn(string $segment): bool => $segment !== ''));

        if (count($segments) < 1 || count($segments) > 2) {
            return null;
        }

        foreach ($categories as $category) {
            if ((string)($category['slug'] ?? '') !== $segments[0]) {
                continue;
            }

            if (count($segments) === 1) {
                return [
                    'category_id' => $category['id'],
                    'subcategory' => '',
                ];
            }

            foreach ((array)($category['subcategories'] ?? []) as $subcategory) {
                if ((string)($subcategory['slug'] ?? '') === $segments[1]) {
                    return [
                        'category_id' => $category['id'],
                        'subcategory' => (string)($subcategory['value'] ?? ''),
                    ];
                }
            }

            return null;
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    public static function categoryPaths(array $categories): array
    {
        $paths = [];
        foreach ($categories as $category) {
            $categoryId = (string)($category['id'] ?? '');
            $categoryPath = (string)($category['path'] ?? '');
            if ($categoryId !== '' && $categoryPath !== '') {
                $paths[$categoryId] = $categoryPath;
            }

            foreach ((array)($category['subcategories'] ?? []) as $subcategory) {
                $subcategoryId = (string)($subcategory['value'] ?? '');
                $subcategoryPath = (string)($subcategory['path'] ?? '');
                if ($subcategoryId !== '' && $subcategoryPath !== '') {
                    $paths[$subcategoryId] = $subcategoryPath;
                }
            }
        }

        return $paths;
    }

    public static function itemPath(array $categoryPaths, int|string $categoryId, int|string $commodityId): string
    {
        $categoryPath = $categoryPaths[(string)$categoryId] ?? '';
        if ($categoryPath === '') {
            return '/item/' . rawurlencode((string)$commodityId);
        }

        return rtrim($categoryPath, '/') . '/' . rawurlencode((string)$commodityId);
    }

    private static function slug(string $value, string $fallback): string
    {
        $value = strtolower(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $value = str_replace('&', ' and ', $value);
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        $value = trim($value, '-');

        return $value !== '' ? $value : $fallback;
    }

    private static function uniqueSlug(string $slug, array &$used): string
    {
        $candidate = $slug;
        $suffix = 2;
        while (isset($used[$candidate])) {
            $candidate = $slug . '-' . $suffix;
            $suffix++;
        }
        $used[$candidate] = true;

        return $candidate;
    }
}
