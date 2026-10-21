<?php
declare(strict_types=1);

namespace App\Plugin\Support;

use App\Model\Category;
use App\Model\Commodity;

final class UpstreamCategoryStatus
{
    /**
     * Return every category that must stay enabled for currently active products.
     *
     * @return array<int, true>
     */
    public static function activeCategoryTreeIds(): array
    {
        $categoryIds = Commodity::query()
            ->where('status', 1)
            ->whereNotNull('category_id')
            ->distinct()
            ->pluck('category_id')
            ->all();

        return array_fill_keys(
            self::expandWithAncestors($categoryIds, Category::flatMap()),
            true
        );
    }

    /**
     * @param array<int, int|string> $categoryIds
     * @param array<int, array{name: string, pid: int}> $categoryMap
     * @return int[]
     */
    public static function expandWithAncestors(array $categoryIds, array $categoryMap): array
    {
        $expanded = [];
        foreach ($categoryIds as $categoryId) {
            $currentId = (int)$categoryId;
            $seen = [];
            while ($currentId > 0 && !isset($seen[$currentId])) {
                $seen[$currentId] = true;
                $expanded[$currentId] = true;
                $currentId = (int)($categoryMap[$currentId]['pid'] ?? 0);
            }
        }

        return array_map('intval', array_keys($expanded));
    }
}
