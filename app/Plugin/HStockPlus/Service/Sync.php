<?php
declare(strict_types=1);

namespace App\Plugin\HStockPlus\Service;

use App\Model\Category;
use App\Model\Commodity;
use App\Model\Coupon;
use App\Model\UserCategory;
use App\Plugin\Support\ExchangeRate;
use App\Plugin\Support\UpstreamPriceRecalculator;
use App\Util\Ini;
use App\Util\Plugin;
use Illuminate\Database\Capsule\Manager as Db;
use Kernel\Exception\JSONException;

class Sync
{
    private const PLUGIN = 'HStockPlus';
    private const DESCRIPTION_MAX_BYTES = 50000;
    private const STOCK_MAX = 2147483647;
    private const ACCOUNT_SUBCATEGORIES = [
        'TikTok',
        'Facebook',
        'Twitter/X',
        'Telegram',
        'Instagram',
        'Discord',
        'Snapchat',
        'Spotify',
        'WhatsApp',
        'OnlyFans',
        '领英',
        'Microsoft',
        'Apple iCloud',
        '行项目',
        'Zalo',
        'Twitch',
        'Reddit',
        'Fiverr',
        '踢出',
        'GitHub',
        'Quora',
        'Tripadvisor',
        'Tumblr',
        'VKontakte',
        'Odnoklassniki',
        'YouTube',
        '蓝空',
        'Apple ID',
        'Pinterest',
        'Threads',
        'Amazon',
        'Ozon',
        '批量预订',
        'GitLab',
        '超级',
        'Trovo',
        'Deezer',
        'Dropbox',
        'OLX',
        'ExitLag',
        '教育科技',
        'Classmates',
        'Bosslike',
        'Tuta',
        'Vps 云服务器',
        'BeReal',
        'Mastodon',
        'Clubhouse',
        'MeWe',
        '其它',
    ];

    /**
     * @throws JSONException
     */
    public function run(array $config): array
    {
        $action = (int)($config['sync_action'] ?? 0);
        if ($action <= 0) {
            return ['last_sync_result' => '未执行同步'];
        }
        if (!in_array($action, [1, 4], true)) {
            $config = ExchangeRate::applyUsdToCny($config, self::PLUGIN);
        }

        $stats = [
            'categories_created' => 0,
            'categories_updated' => 0,
            'categories_deleted' => 0,
            'categories_enabled' => 0,
            'products_created' => 0,
            'products_updated' => 0,
            'products_price_updated' => 0,
            'products_skipped' => 0,
        ];
        if ($action === 4) {
            $result = UpstreamPriceRecalculator::recalculate(self::PLUGIN, $config);
            $config = $result['config'];
            $stats['products_price_updated'] = (int)$result['updated'];
            $stats['products_skipped'] = (int)$result['skipped'];
            return $this->finish($config, $action, $stats);
        }

        $client = new Client(
            $this->normalizeApiUrl((string)($config['api_url'] ?? 'https://hstockplus.com/api/v2')),
            trim((string)($config['api_key'] ?? '')),
            (int)($config['timeout'] ?? 30)
        );

        $filters = $this->filters($config);
        if ($action === 1) {
            $this->syncCategoriesOnly($client, $filters, $config, $stats);
            return $this->finish($config, $action, $stats);
        }

        $source = strtolower(trim((string)($config['sync_source'] ?? 'services')));
        $services = $this->fetchSourceRows($client, $source, $filters, $config);
        $categoryHints = $this->categoryHints($client, $filters);
        $categories = [];
        Db::connection()->transaction(function () use ($services, $config, $action, $categoryHints, &$stats, &$categories): void {
            $this->cleanupLegacyCategories($stats);
            $this->normalizeAccountSubcategories($config, $stats);
            foreach ($services as $index => $service) {
                if (!is_array($service)) {
                    $stats['products_skipped']++;
                    continue;
                }

                $serviceId = trim((string)($service['service'] ?? $service['id'] ?? ''));
                $name = trim((string)($service['name'] ?? $service['title'] ?? ''));
                if ($serviceId === '' || $name === '') {
                    $stats['products_skipped']++;
                    continue;
                }

                $path = $this->categoryPath($service, $categoryHints);
                if (!$this->isEnabledTopCategory((string)($path[0] ?? ''), $config)) {
                    $stats['products_skipped']++;
                    continue;
                }

                $categoryKey = implode('>', $path);
                if (!isset($categories[$categoryKey])) {
                    $categories[$categoryKey] = $this->syncCategoryPath($path, $config, $stats);
                }
                $category = $categories[$categoryKey];
                $category->sort = $this->categorySort((string)end($path));
                $category->save();

                if ($action === 1) {
                    continue;
                }

                $commodity = $this->syncCommodity($category, $service, $config, $action, $stats, $index + 1);
                if (!$commodity) {
                    continue;
                }
            }

            $this->reconcilePluginCategories($config, $stats);
        });

        return $this->finish($config, $action, $stats);
    }

    /**
     * @throws JSONException
     */
    private function syncCategoriesOnly(Client $client, array $filters, array $config, array &$stats): void
    {
        $rows = $this->extractRows($client->categories($filters));
        if (!$rows) {
            throw new JSONException('HStockPlus 分类列表为空或返回格式异常');
        }

        Db::connection()->transaction(function () use ($rows, $config, &$stats): void {
            $this->cleanupLegacyCategories($stats);
            $this->syncCategoryTree($rows, $config, $stats);
            $this->normalizeAccountSubcategories($config, $stats);
            $this->reconcilePluginCategories($config, $stats);
        });
    }

    private function categoryHints(Client $client, array $filters): array
    {
        try {
            $rows = $this->extractRows($client->categories($filters));
        } catch (\Throwable) {
            return [];
        }

        $hints = [
            'parent_by_child' => [],
            'child_by_id' => [],
            'parent_by_child_id' => [],
        ];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $parent = $this->normalizeTopCategory($this->cleanTitle($this->fieldValue($row, ['category', 'name', 'title'])));
            if ($parent === '') {
                continue;
            }
            $isAccountParent = $this->normalizeTopCategory($parent) === '账户';
            foreach ($this->extractSubcategories($row, $isAccountParent) as $child) {
                $name = (string)$child['name'];
                $hints['parent_by_child'][$this->categoryLookupKey($name)] = $parent;
                if ((string)$child['id'] !== '') {
                    $hints['child_by_id'][(string)$child['id']] = $name;
                    $hints['parent_by_child_id'][(string)$child['id']] = $parent;
                }
            }
        }

        return $hints;
    }

    private function syncCategoryTree(array $rows, array $config, array &$stats): void
    {
        foreach ($rows as $row) {
            if (is_string($row) || is_numeric($row)) {
                $this->syncCategoryPath([(string)$row], $config, $stats);
                continue;
            }

            if (!is_array($row)) {
                continue;
            }

            $parent = $this->normalizeTopCategory($this->cleanTitle($this->fieldValue($row, ['category', 'name', 'title'])));
            if ($parent === '') {
                continue;
            }
            $parentCategory = $this->syncCategoryPath([$parent], $config, $stats);
            foreach ($this->extractSubcategoryNames($row, $this->normalizeTopCategory($parent) === '账户') as $index => $child) {
                $category = $this->syncCategoryPath([$parent, $child], $config, $stats);
                $category->sort = $this->categorySort($child);
                $category->save();
            }
            $parentCategory->sort = $this->categorySort($parent);
            $parentCategory->save();
        }
    }

    private function extractSubcategoryNames(array $row, bool $normalizeAccount = false): array
    {
        return array_values(array_unique(array_map(static fn(array $sub): string => (string)$sub['name'], $this->extractSubcategories($row, $normalizeAccount))));
    }

    private function extractSubcategories(array $row, bool $normalizeAccount = false): array
    {
        $raw = $row['subcategories'] ?? $row['children'] ?? $row['subs'] ?? [];
        if (!is_array($raw)) {
            return [];
        }

        $items = [];
        foreach ($raw as $sub) {
            $name = is_array($sub)
                ? $this->cleanTitle($this->fieldValue($sub, ['name', 'category', 'title', 'label']))
                : $this->cleanTitle((string)$sub);
            if ($name !== '') {
                if ($normalizeAccount) {
                    $name = $this->normalizeAccountSubcategory($name);
                }
                $id = is_array($sub) ? $this->fieldValue($sub, ['id', '_id', 'subcategory_id', 'subcategoryId', 'value']) : '';
                $items[$name] = [
                    'name' => $name,
                    'id' => $id,
                ];
            }
        }

        return array_values($items);
    }


    private function finish(array $config, int $action, array $stats): array
    {
        if ($this->isGeneratedMapping((string)($config['mapping'] ?? ''))) {
            $config['mapping'] = '';
        }
        $config['last_sync_at'] = date('Y-m-d H:i:s');
        $config['last_sync_result'] = sprintf(
            '%s：分类新增%d/更新%d，空分类删除%d/恢复%d，商品新增%d/更新%d，价格更新%d，跳过%d',
            $this->actionName($action),
            $stats['categories_created'],
            $stats['categories_updated'],
            $stats['categories_deleted'],
            $stats['categories_enabled'],
            $stats['products_created'],
            $stats['products_updated'],
            $stats['products_price_updated'],
            $stats['products_skipped']
        );
        $config['sync_action'] = '0';
        unset($config['sync_start_page'], $config['sync_max_pages']);

        setConfig($config, BASE_PATH . '/app/Plugin/' . self::PLUGIN . '/Config/Config.php');
        Plugin::log(self::PLUGIN, '同步完成：' . $config['last_sync_result']);

        return $stats + [
                'last_sync_at' => $config['last_sync_at'],
                'last_sync_result' => $config['last_sync_result'],
            ];
    }

    private function filters(array $config): array
    {
        $filters = ['limit' => 0];
        foreach (['lang', 'entity_type' => 'entityType'] as $key => $apiKey) {
            if (is_int($key)) {
                $key = $apiKey;
            }
            $value = trim((string)($config[$key] ?? ''));
            if ($value !== '') {
                $filters[(string)$apiKey] = $value;
            }
        }
        return $filters;
    }

    private function extractRows(array $response): array
    {
        if ($this->isList($response)) {
            return $response;
        }

        foreach (['data', 'categories', 'services', 'products', 'rows', 'items'] as $key) {
            if (isset($response[$key]) && is_array($response[$key])) {
                return $response[$key];
            }
        }

        return [];
    }

    /**
     * @throws JSONException
     */
    private function fetchSourceRows(Client $client, string $source, array $filters, array $config): array
    {
        $maxRows = max(0, (int)($config['sync_limit'] ?? 0));
        $pageSize = max(1, (int)($config['sync_page_size'] ?? 100));
        $pageSize = min($pageSize, 500);
        $page = max(1, (int)($config['sync_start_page'] ?? 1));
        $maxPages = max(0, (int)($config['sync_max_pages'] ?? 0));
        $pagesFetched = 0;
        $rows = [];

        while ($page <= 10000) {
            $pageFilters = $filters + [
                    'page' => $page,
                    'limit' => $maxRows > 0 ? min($pageSize, max(1, $maxRows - count($rows))) : $pageSize,
                ];
            $response = $source === 'products' ? $client->products($pageFilters) : $client->services($pageFilters);
            $pageRows = $this->extractRows($response);
            if (!$pageRows) {
                break;
            }
            $pagesFetched++;

            foreach ($pageRows as $row) {
                $rows[] = $row;
                if ($maxRows > 0 && count($rows) >= $maxRows) {
                    break 2;
                }
            }

            $totalPages = max(0, (int)($response['total_pages'] ?? 0));
            if ($totalPages > 0 && $page >= $totalPages) {
                break;
            }
            if (count($pageRows) < $pageSize) {
                break;
            }
            if ($maxPages > 0 && $pagesFetched >= $maxPages) {
                break;
            }

            $page++;
        }

        return $rows;
    }

    private function extractCategoryNames(array $rows): array
    {
        $names = [];
        foreach ($rows as $row) {
            if (is_string($row) || is_numeric($row)) {
                $name = trim((string)$row);
                if ($name !== '') {
                    $names[$name] = $name;
                }
                continue;
            }

            if (!is_array($row)) {
                continue;
            }

            $name = trim((string)($row['name'] ?? $row['category'] ?? $row['title'] ?? ''));
            if ($name !== '') {
                $names[$name] = $name;
            }

            // acg-faka commodities point to one flat category_id, so only sync
            // top-level categories here. Product sync can still preserve subcategory info.
        }

        return array_values($names);
    }

    private function syncCategoryPath(array $path, array $config, array &$stats): Category
    {
        $parent = null;
        $category = null;
        foreach ($path as $index => $name) {
            $category = $this->syncCategoryNode((string)$name, $config, $stats, $parent, ($index + 1) * 10);
            $parent = $category;
        }

        return $category ?? $this->syncCategoryNode('默认分类', $config, $stats, null, 9999);
    }

    private function syncCategoryNode(string $upstreamCategory, array $config, array &$stats, ?Category $parent = null, int $sort = 0): Category
    {
        $prefix = (string)($config['sync_category_prefix'] ?? '');
        $cleanCategory = $this->cleanTitle($upstreamCategory);
        $name = trim($prefix . $cleanCategory);
        if ($name === trim($prefix)) {
            $cleanCategory = '默认分类';
            $name = trim($prefix . $cleanCategory);
        }

        $query = Category::query()->where('owner', 0)->where('name', $name);
        if ($parent) {
            $query->where('pid', $parent->id);
        } else {
            $query->where(function ($builder): void {
                $builder->whereNull('pid')->orWhere('pid', 0);
            });
        }

        /** @var Category|null $category */
        $category = $query->first();
        foreach ($this->legacyCategoryNames($cleanCategory, $prefix) as $oldName) {
            if ($category || $oldName === $name) {
                continue;
            }
            $category = Category::query()->where('owner', 0)->where('name', $oldName)->first();
        }
        $created = false;
        if (!$category) {
            $category = new Category();
            $category->owner = 0;
            $category->create_time = date('Y-m-d H:i:s');
            $category->hide = 0;
            $category->icon = '/favicon.ico';
            $created = true;
        }

        $category->name = $name;
        $category->pid = $parent?->id;
        $category->sort = $sort ?: $this->categorySort($cleanCategory);
        $category->icon = $this->categoryIcon($upstreamCategory);
        $category->status = (int)($config['default_category_status'] ?? 1);
        $category->hide = 0;
        $category->save();

        $stats[$created ? 'categories_created' : 'categories_updated']++;
        return $category;
    }

    private function syncCategory(string $upstreamCategory, array $config, array &$stats): Category
    {
        return $this->syncCategoryPath([$upstreamCategory], $config, $stats);
    }

    private function legacyCategoryNames(string $category, string $prefix): array
    {
        $names = [];
        foreach (array_unique(['HStockPlus-', $prefix]) as $legacyPrefix) {
            $legacyPrefix = trim($legacyPrefix);
            if ($legacyPrefix === '') {
                continue;
            }
            $names[] = trim($legacyPrefix . $category);
        }

        return $names;
    }

    public function cleanupLegacyCategories(?array &$stats = null): void
    {
        if ($stats === null) {
            $stats = ['categories_updated' => 0];
        }

        $legacyCategories = Category::query()
            ->where('name', 'like', 'HStockPlus%')
            ->get();

        foreach ($legacyCategories as $legacyCategory) {
            $newName = $this->cleanTitle((string)$legacyCategory->name);
            if ($newName === '') {
                continue;
            }

            /** @var Category|null $target */
            $target = Category::query()
                ->where('owner', (int)$legacyCategory->owner)
                ->where('name', $newName)
                ->where('id', '<>', $legacyCategory->id)
                ->first();

            if ($target) {
                Commodity::query()
                    ->where('category_id', $legacyCategory->id)
                    ->update(['category_id' => $target->id]);
                Coupon::query()
                    ->where('category_id', $legacyCategory->id)
                    ->update(['category_id' => $target->id]);
                UserCategory::query()
                    ->where('category_id', $legacyCategory->id)
                    ->update(['category_id' => $target->id]);
                Category::query()
                    ->where('pid', $legacyCategory->id)
                    ->update(['pid' => $target->id]);

                $remaining = Commodity::query()->where('category_id', $legacyCategory->id)->count();
                $childCount = Category::query()->where('pid', $legacyCategory->id)->count();
                if ((int)$remaining === 0 && (int)$childCount === 0) {
                    $legacyCategory->delete();
                }
            } else {
                $legacyCategory->name = $newName;
                $legacyCategory->save();
            }

            $stats['categories_updated']++;
        }
    }

    public function normalizeAccountSubcategories(array $config = [], ?array &$stats = null): void
    {
        if ($stats === null) {
            $stats = ['categories_updated' => 0, 'categories_deleted' => 0];
        }
        $stats['categories_updated'] ??= 0;
        $stats['categories_deleted'] ??= 0;

        $accountParents = Category::query()
            ->where('owner', 0)
            ->where(function ($query): void {
                $query->whereNull('pid')->orWhere('pid', 0);
            })
            ->where(function ($query): void {
                $query->where('icon', 'like', '/app/Plugin/HStockPlus/Assets/icons/%')
                    ->orWhere('name', '账户')
                    ->orWhere('name', 'Accounts')
                    ->orWhere('name', 'like', 'HStockPlus%账户%')
                    ->orWhere('name', 'like', 'HStockPlus%Accounts%');
            })
            ->get();

        foreach ($accountParents as $parent) {
            $normalizedParentName = $this->normalizeTopCategory((string)$parent->name);
            if ($normalizedParentName !== '账户' && $this->cleanTitle((string)$parent->name) !== '账户') {
                continue;
            }

            /** @var Category|null $targetParent */
            $targetParent = Category::query()
                ->where('owner', 0)
                ->where(function ($query): void {
                    $query->whereNull('pid')->orWhere('pid', 0);
                })
                ->where('name', '账户')
                ->where('id', '<>', $parent->id)
                ->first();
            if ($targetParent) {
                $this->mergeCategoryInto($parent, $targetParent);
                $parent = $targetParent;
                $stats['categories_updated']++;
                $stats['categories_deleted']++;
            }

            $parentChanged = false;
            if ((string)$parent->name !== '账户') {
                $parent->name = '账户';
                $parentChanged = true;
            }
            $parentIcon = $this->categoryIcon('账户');
            if ((string)$parent->icon !== $parentIcon) {
                $parent->icon = $parentIcon;
                $parentChanged = true;
            }
            $parentSort = $this->categorySort('账户');
            if ((int)$parent->sort !== $parentSort) {
                $parent->sort = $parentSort;
                $parentChanged = true;
            }
            if ($parentChanged) {
                $parent->save();
                $stats['categories_updated']++;
            }

            $children = Category::query()
                ->where('owner', 0)
                ->where('pid', $parent->id)
                ->orderBy('id', 'asc')
                ->get();

            foreach ($children as $child) {
                $normalized = $this->normalizeAccountSubcategory((string)$child->name);
                $targetName = $normalized !== '' ? $normalized : '其它';
                $target = $this->accountSubcategoryTarget($parent, $child, $targetName);

                if ((int)$target->id !== (int)$child->id) {
                    $this->mergeCategoryInto($child, $target);
                    $stats['categories_updated']++;
                    $stats['categories_deleted']++;
                    continue;
                }

                if ($this->applyAccountSubcategoryMeta($child, $targetName)) {
                    $stats['categories_updated']++;
                }
            }
        }
    }

    private function accountSubcategoryTarget(Category $parent, Category $source, string $targetName): Category
    {
        /** @var Category|null $target */
        $target = Category::query()
            ->where('owner', 0)
            ->where('pid', $parent->id)
            ->where('name', $targetName)
            ->where('id', '<>', $source->id)
            ->first();
        if ($target) {
            $this->applyAccountSubcategoryMeta($target, $targetName);
            return $target;
        }

        if ((string)$source->name === $targetName) {
            return $source;
        }

        $target = new Category();
        $target->owner = 0;
        $target->pid = $parent->id;
        $target->name = $targetName;
        $target->create_time = date('Y-m-d H:i:s');
        $target->status = (int)$source->status;
        $target->hide = 0;
        $target->icon = $this->categoryIcon($targetName);
        $target->sort = $this->categorySort($targetName);
        $target->save();

        return $target;
    }

    private function applyAccountSubcategoryMeta(Category $category, string $name): bool
    {
        $changed = false;
        if ((string)$category->name !== $name) {
            $category->name = $name;
            $changed = true;
        }
        $icon = $this->categoryIcon($name);
        if ((string)$category->icon !== $icon) {
            $category->icon = $icon;
            $changed = true;
        }
        $sort = $this->categorySort($name);
        if ((int)$category->sort !== $sort) {
            $category->sort = $sort;
            $changed = true;
        }
        if ((int)$category->hide !== 0) {
            $category->hide = 0;
            $changed = true;
        }
        if ($changed) {
            $category->save();
        }

        return $changed;
    }

    private function mergeCategoryInto(Category $source, Category $target): void
    {
        Commodity::query()
            ->where('category_id', $source->id)
            ->update(['category_id' => $target->id]);
        Coupon::query()
            ->where('category_id', $source->id)
            ->update(['category_id' => $target->id]);
        UserCategory::query()
            ->where('category_id', $source->id)
            ->update(['category_id' => $target->id]);
        Category::query()
            ->where('pid', $source->id)
            ->update(['pid' => $target->id]);

        $target->icon = $this->categoryIcon((string)$target->name);
        $target->sort = $this->categorySort((string)$target->name);
        $target->hide = 0;
        $target->save();

        $remaining = Commodity::query()->where('category_id', $source->id)->count();
        $children = Category::query()->where('pid', $source->id)->count();
        if ((int)$remaining === 0 && (int)$children === 0) {
            $source->delete();
        }
    }

    public function reconcilePluginCategories(array $config = [], ?array &$stats = null): void
    {
        if ($stats === null) {
            $stats = ['categories_deleted' => 0, 'categories_enabled' => 0];
        }
        $stats['categories_deleted'] ??= 0;
        $stats['categories_enabled'] ??= 0;

        $productCategoryIds = Commodity::query()
            ->where('code', 'like', 'HSP\_%')
            ->whereNotNull('category_id')
            ->distinct()
            ->pluck('category_id')
            ->all();

        $categories = Category::query()
            ->where('owner', 0)
            ->where(function ($query) use ($productCategoryIds): void {
                $query->where('icon', 'like', '/app/Plugin/HStockPlus/Assets/icons/%')
                    ->orWhere('name', 'like', 'HStockPlus%');

                if ($productCategoryIds) {
                    $query->orWhereIn('id', $productCategoryIds);
                }
            })
            ->get();

        if ($categories->isEmpty()) {
            return;
        }

        $categoryIds = $categories->pluck('id')->map(static fn($id): int => (int)$id)->all();
        $childrenByParent = Category::query()
            ->whereIn('pid', $categoryIds)
            ->get(['id', 'pid'])
            ->groupBy('pid')
            ->map(static fn($items) => $items->pluck('id')->map(static fn($id): int => (int)$id)->all())
            ->all();
        $allCategoryIds = $categoryIds;
        foreach ($categoryIds as $categoryId) {
            array_push($allCategoryIds, ...$this->descendantCategoryIds((int)$categoryId, $childrenByParent));
        }
        $allCategoryIds = array_values(array_unique($allCategoryIds));
        $commodityCounts = Commodity::query()
            ->whereIn('category_id', $allCategoryIds)
            ->selectRaw('category_id, count(*) as aggregate')
            ->groupBy('category_id')
            ->pluck('aggregate', 'category_id')
            ->all();

        $defaultStatus = (int)($config['default_category_status'] ?? 1);
        foreach ($categories as $category) {
            $countIds = array_merge([(int)$category->id], $this->descendantCategoryIds((int)$category->id, $childrenByParent));
            $commodityCount = array_sum(array_map(static fn(int $categoryId): int => (int)($commodityCounts[$categoryId] ?? 0), $countIds));
            if ($commodityCount <= 0) {
                Coupon::query()->where('category_id', $category->id)->update(['category_id' => 0]);
                UserCategory::query()->where('category_id', $category->id)->delete();
                Category::query()->where('pid', $category->id)->update(['pid' => null]);
                $category->delete();
                $stats['categories_deleted']++;
                continue;
            }

            if ((int)$category->status !== $defaultStatus || (int)$category->hide !== 0) {
                $category->status = $defaultStatus;
                $category->hide = 0;
                $category->save();
                $stats['categories_enabled']++;
            }
        }
    }

    private function descendantCategoryIds(int $parentId, array $childrenByParent): array
    {
        $ids = [];
        foreach ($childrenByParent[$parentId] ?? [] as $childId) {
            $ids[] = (int)$childId;
            array_push($ids, ...$this->descendantCategoryIds((int)$childId, $childrenByParent));
        }
        return $ids;
    }

    private function syncCommodity(Category $category, array $service, array $config, int $action, array &$stats, int $sort): ?Commodity
    {
        $serviceId = trim((string)($service['service'] ?? $service['id'] ?? ''));
        $code = 'HSP_' . preg_replace('/[^A-Za-z0-9_]/', '_', $serviceId);

        /** @var Commodity|null $commodity */
        $commodity = Commodity::query()->where('code', $code)->first();
        $created = false;
        if (!$commodity) {
            $commodity = new Commodity();
            $commodity->code = $code;
            $commodity->owner = 0;
            $commodity->create_time = date('Y-m-d H:i:s');
            $created = true;
        }

        $cost = $this->costPerUnit($service, $config);
        $price = $this->price($cost, $config);

        if ($action === 4) {
            if ($created) {
                $stats['products_skipped']++;
                return null;
            }
            $commodity->factory_price = $cost;
            $commodity->price = $price;
            $commodity->user_price = $price;
            $commodity->category_id = $category->id;
            $commodity->sort = $sort;
            $commodity->save();
            $stats['products_price_updated']++;
            return $commodity;
        }

        if ($action === 3 || $created) {
            $commodity->factory_price = $cost;
            $commodity->price = $price;
            $commodity->user_price = $price;
            if (!$created) {
                $stats['products_price_updated']++;
            }
        }

        $commodity->category_id = $category->id;
        $commodity->name = mb_substr($this->cleanTitle((string)($service['name'] ?? $service['title'] ?? '上游商品')), 0, 255);
        $commodity->description = $this->description($service, $config);
        $commodity->cover = $this->cover($service, $category->icon ?: '/favicon.ico');
        $commodity->status = (int)($config['default_product_status'] ?? 1);
        $commodity->api_status = 1;
        $commodity->delivery_way = 1;
        $commodity->delivery_auto_mode = 0;
        $commodity->delivery_message = '订单已提交上游处理，请稍后查看订单状态。';
        $commodity->contact_type = 0;
        $commodity->password_status = 0;
        $commodity->sort = $sort;
        $commodity->coupon = 0;
        $commodity->shared_id = null;
        $commodity->shared_code = '';
        $commodity->shared_premium = 0;
        $commodity->shared_premium_type = 0;
        $commodity->seckill_status = 0;
        $commodity->draft_status = 0;
        $commodity->draft_premium = 0;
        $commodity->inventory_hidden = 0;
        $commodity->recommend = (int)filter_var($service['isRecommended'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $commodity->send_email = 0;
        $commodity->only_user = 0;
        $commodity->purchase_count = 0;
        $commodity->widget = '';
        $commodity->level_price = null;
        $commodity->level_disable = 0;
        $commodity->minimum = max(0, (int)($service['min'] ?? 1));
        $commodity->maximum = max(0, (int)($service['max'] ?? 0));
        $commodity->shared_sync = 0;
        $commodity->inventory_sync = 0;
        $commodity->shared_amount_sync = 0;
        $commodity->shared_config_sync = 0;
        $commodity->hide = 0;
        $commodity->stock = $this->stockValue($service['stock'] ?? null, $config);
        $commodity->config = $this->serviceConfig($service);
        $commodity->save();

        $stats[$created ? 'products_created' : 'products_updated']++;
        return $commodity;
    }

    private function mappingValue(array $service, array $config): array
    {
        $serviceId = (string)($service['service'] ?? $service['id'] ?? '');
        return [
            'service' => is_numeric($serviceId) ? (int)$serviceId : $serviceId,
            'link_source' => (string)($config['default_link_source'] ?? 'contact'),
            'quantity' => 'order_num',
            'stock' => (string)$this->stockValue($service['stock'] ?? null, $config),
            'entity_type' => (string)($service['entityType'] ?? $service['entity_type'] ?? ''),
            'auto_delivery' => filter_var($service['autoDelivery'] ?? false, FILTER_VALIDATE_BOOLEAN) ? '1' : '0',
        ];
    }

    private function isGeneratedMapping(string $raw): bool
    {
        $raw = trim($raw);
        if ($raw === '') {
            return false;
        }

        $mapping = json_decode($raw, true);
        if (!is_array($mapping) || $mapping === []) {
            return false;
        }

        $sampled = 0;
        foreach ($mapping as $key => $value) {
            if (!is_array($value)) {
                return false;
            }
            if (!str_starts_with((string)$key, 'HSP_') && !ctype_digit((string)$key)) {
                return false;
            }
            if (!array_key_exists('service', $value) || !array_key_exists('quantity', $value)) {
                return false;
            }
            $sampled++;
            if ($sampled >= 20) {
                return true;
            }
        }

        return $sampled > 0;
    }

    private function categoryName(array $service): string
    {
        $value = $this->cleanTitle($this->fieldValue($service, ['category']));
        if ($value !== '') {
            return $value;
        }

        $entityType = trim((string)($service['entityType'] ?? $service['entity_type'] ?? ''));
        if ($entityType === 'product') {
            return '产品货源';
        }
        if ($entityType === 'smm') {
            return '增长服务';
        }

        return '其他服务';
    }

    private function categoryPath(array $service, array $categoryHints = []): array
    {
        $category = $this->cleanTitle($this->fieldValue($service, ['category']));
        $subcategory = $this->cleanTitle($this->fieldValue($service, ['subcategoryName', 'subcategory_name', 'subCategoryName', 'subcategory', 'sub_category']));
        $subcategoryId = $this->fieldValue($service, ['subcategoryId', 'subcategory_id', 'subCategoryId']);
        $rawParts = $this->splitCategoryPath($category);

        if ($subcategory === '' && $subcategoryId !== '') {
            $subcategory = $this->cleanTitle((string)($categoryHints['child_by_id'][$subcategoryId] ?? ''));
        }
        if ($subcategory === '') {
            $subcategory = $rawParts[1] ?? '';
        }
        if ($subcategory === '' && isset($rawParts[0]) && !$this->isTopCategoryLabel($rawParts[0])) {
            $subcategory = $rawParts[0];
        }

        $rawLeaf = $subcategory !== '' ? $subcategory : ($rawParts[1] ?? $rawParts[0] ?? '');
        $hintParent = '';
        if ($subcategoryId !== '') {
            $hintParent = $this->cleanTitle((string)($categoryHints['parent_by_child_id'][$subcategoryId] ?? ''));
        }
        if ($hintParent === '' && $rawLeaf !== '') {
            $hintParent = $this->cleanTitle((string)($categoryHints['parent_by_child'][$this->categoryLookupKey($rawLeaf)] ?? ''));
        }

        $parent = $hintParent !== '' ? $this->normalizeTopCategory($hintParent) : $this->hstockplusTopCategory($service, $category, $rawLeaf);
        $child = $parent === '账户'
            ? $this->normalizeAccountSubcategory($rawLeaf !== '' ? $rawLeaf : (string)($service['name'] ?? ''))
            : ($rawLeaf !== '' ? $rawLeaf : $parent);

        return [$parent, $child];
    }

    private function hstockplusTopCategory(array $service, string $category, string $subcategory): string
    {
        $entityType = trim((string)($service['entityType'] ?? $service['entity_type'] ?? ''));
        $text = mb_strtolower($category . ' ' . $subcategory . ' ' . (string)($service['name'] ?? ''), 'UTF-8');
        $categoryText = mb_strtolower($category, 'UTF-8');

        if (str_contains($categoryText, '账号') || str_contains($categoryText, '账户') || str_contains($categoryText, 'account')) {
            return '账户';
        }

        if ($entityType === 'smm' || str_contains($text, '增长') || str_contains($text, '粉丝') || str_contains($text, '关注') || str_contains($text, '观看') || str_contains($text, '点赞') || str_contains($text, '成员')) {
            return '增长服务';
        }

        if (str_contains($text, '代理') || str_contains($text, 'proxy') || str_contains($text, 'proxies') || str_contains($text, 'vpn') || str_contains($text, 'vps')) {
            return '代理服务';
        }

        if (str_contains($text, '短信') || str_contains($text, 'sms') || str_contains($text, '验证') || str_contains($text, 'verification')) {
            return '短信验证';
        }

        if (str_contains($text, '评论') || str_contains($text, 'review') || str_contains($text, '评价') || str_contains($text, 'trustpilot')) {
            return '评论';
        }

        if (str_contains($text, '邮件') || str_contains($text, '邮箱') || str_contains($text, 'email') || str_contains($text, 'mail') || str_contains($text, 'gmail') || str_contains($text, 'hotmail') || str_contains($text, 'outlook')) {
            return '电子邮件';
        }

        return '账户';
    }

    private function normalizeTopCategory(string $category): string
    {
        $key = $this->categoryLookupKey($category);
        return match ($key) {
            'account', 'accounts', '账号', '账户' => '账户',
            'email', 'emails', 'mail', '电子邮件', '邮箱', '邮件' => '电子邮件',
            'smm', 'growth', 'growth service', 'growth services', '增长', '增长服务' => '增长服务',
            'proxy', 'proxies', '代理', '代理服务' => '代理服务',
            'sms', 'verification', '短信', '短信验证' => '短信验证',
            'review', 'reviews', '评论', '评价' => '评论',
            default => $category,
        };
    }

    private function isTopCategoryLabel(string $category): bool
    {
        return in_array($this->normalizeTopCategory($category), ['账户', '电子邮件', '增长服务', '代理服务', '短信验证', '评论'], true);
    }

    private function isEnabledTopCategory(string $category, array $config): bool
    {
        $enabled = $this->enabledTopCategories($config);
        return in_array($this->cleanTitle($category), $enabled, true);
    }

    private function enabledTopCategories(array $config): array
    {
        $raw = (string)($config['enabled_top_categories'] ?? '账户');
        $items = preg_split('/[,，|\\s]+/u', $raw) ?: [];
        $enabled = array_values(array_filter(array_map(fn(string $item): string => $this->cleanTitle($item), $items), static fn(string $name): bool => $name !== ''));

        return $enabled ?: ['账户'];
    }

    private function splitCategoryPath(string $category): array
    {
        $category = trim($category);
        if ($category === '') {
            return [];
        }

        $parts = preg_split('/\s*(?:[-_|>＞:：]+|\s+\/\s+)\s*/u', $category) ?: [];
        $parts = array_values(array_filter(array_map(fn(string $part): string => $this->cleanTitle($part), $parts), static fn(string $part): bool => $part !== ''));

        if (count($parts) <= 1) {
            return $parts;
        }

        return [$parts[0], implode('-', array_slice($parts, 1))];
    }

    private function fallbackParentCategory(array $service): string
    {
        $entityType = trim((string)($service['entityType'] ?? $service['entity_type'] ?? ''));
        if ($entityType === 'product') {
            return '产品货源';
        }
        if ($entityType === 'smm') {
            return '增长服务';
        }
        return '其他服务';
    }

    private function categoryLookupKey(string $value): string
    {
        return mb_strtolower($this->cleanTitle($value), 'UTF-8');
    }

    private function normalizeAccountSubcategory(string $value): string
    {
        $value = $this->cleanTitle($value);
        if ($value === '') {
            return '其它';
        }

        $parts = $this->splitCategoryPath($value);
        if (count($parts) >= 2 && $this->normalizeTopCategory($parts[0]) === '账户') {
            $value = $parts[1];
        }

        $normalized = $this->accountSubcategoryAliases()[$this->subcategoryAliasKey($value)] ?? null;
        if ($normalized !== null) {
            return $normalized;
        }

        $text = $this->subcategoryAliasKey($value);
        foreach ($this->accountSubcategoryAliases() as $needle => $category) {
            if (mb_strlen($needle, 'UTF-8') >= 3 && str_contains($text, $needle)) {
                return $category;
            }
        }

        return '其它';
    }

    private function accountSubcategoryAliases(): array
    {
        $aliases = [];
        foreach (self::ACCOUNT_SUBCATEGORIES as $category) {
            $aliases[$this->subcategoryAliasKey($category)] = $category;
        }

        return $aliases + [
                'tik tok' => 'TikTok',
                'fb' => 'Facebook',
                'twitter' => 'Twitter/X',
                'x' => 'Twitter/X',
                'x twitter' => 'Twitter/X',
                'twitter x' => 'Twitter/X',
                'tg' => 'Telegram',
                'ig' => 'Instagram',
                'ins' => 'Instagram',
                'snap' => 'Snapchat',
                'linkedin' => '领英',
                'linked in' => '领英',
                'outlook' => 'Microsoft',
                'hotmail' => 'Microsoft',
                'icloud' => 'Apple iCloud',
                'apple icloud' => 'Apple iCloud',
                'line' => '行项目',
                'kick' => '踢出',
                'trip advisor' => 'Tripadvisor',
                'vk' => 'VKontakte',
                'vkontakte' => 'VKontakte',
                'ok' => 'Odnoklassniki',
                'odnoklassniki' => 'Odnoklassniki',
                'yt' => 'YouTube',
                'youtube' => 'YouTube',
                'bluesky' => '蓝空',
                'blue sky' => '蓝空',
                'appleid' => 'Apple ID',
                'apple id' => 'Apple ID',
                'pin' => 'Pinterest',
                'bulk pre order' => '批量预订',
                'bulk preorder' => '批量预订',
                'gitlab' => 'GitLab',
                'super' => '超级',
                'education' => '教育科技',
                'edtech' => '教育科技',
                'vps' => 'Vps 云服务器',
                'cloud server' => 'Vps 云服务器',
                'cloud servers' => 'Vps 云服务器',
                'other' => '其它',
                'others' => '其它',
            ];
    }

    private function subcategoryAliasKey(string $value): string
    {
        $value = mb_strtolower($this->cleanTitle($value), 'UTF-8');
        $value = str_replace(['/', '-', '_', '|', '：', ':'], ' ', $value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        return trim($value);
    }

    private function categoryIcon(string $category): string
    {
        $text = mb_strtolower($category, 'UTF-8');
        $icons = [
            '账户' => 'other',
            '电子邮件' => 'other',
            '增长服务' => 'website-traffic',
            '代理服务' => 'website-traffic',
            '短信验证' => 'other',
            '评论' => 'other',
            'instagram' => 'instagram',
            'tiktok' => 'tiktok',
            'twitter' => 'twitter',
            'youtube' => 'youtube',
            'telegram' => 'telegram',
            'facebook' => 'facebook',
            'spotify' => 'spotify',
            'discord' => 'discord',
            'twitch' => 'twitch',
            'reddit' => 'reddit',
            'linkedin' => 'linkedin',
            'github' => 'github',
            'whatsapp' => 'whatsapp',
            'onlyfans' => 'other',
            '领英' => 'linkedin',
            'linkedin' => 'linkedin',
            'microsoft' => 'other',
            'icloud' => 'apple',
            'apple id' => 'apple',
            '行项目' => 'line',
            'zalo' => 'other',
            'fiverr' => 'other',
            'google' => 'google-maps',
            'quora' => 'quora',
            'tripadvisor' => 'trustpilot',
            'tumblr' => 'other',
            'vkontakte' => 'vk',
            'vk' => 'vk',
            'odnoklassniki' => 'other',
            'pinterest' => 'pinterest',
            'threads' => 'other',
            'amazon' => 'other',
            'ozon' => 'other',
            'gitlab' => 'github',
            'trovo' => 'other',
            'deezer' => 'soundcloud',
            'dropbox' => 'other',
            'olx' => 'other',
            'exitlag' => 'other',
            'classmates' => 'other',
            'bosslike' => 'other',
            'tuta' => 'other',
            'bereal' => 'other',
            'mastodon' => 'other',
            'clubhouse' => 'other',
            'mewe' => 'other',
            'website' => 'website-traffic',
            'traffic' => 'website-traffic',
        ];

        foreach ($icons as $needle => $file) {
            if (str_contains($text, $needle)) {
                return '/app/Plugin/' . self::PLUGIN . '/Assets/icons/' . $file . '.svg';
            }
        }

        return '/app/Plugin/' . self::PLUGIN . '/Assets/icons/other.svg';
    }

    private function categorySort(string $category): int
    {
        $order = ['账户', '电子邮件', '增长服务', '代理服务', '短信验证', '评论', '其他服务'];
        $position = array_search($category, $order, true);
        if ($position !== false) {
            return ($position + 1) * 10;
        }

        $position = array_search($this->normalizeAccountSubcategory($category), self::ACCOUNT_SUBCATEGORIES, true);
        return $position === false ? 9999 : ($position + 1) * 10;
    }

    private function costPerUnit(array $service, array $config): float
    {
        $rate = (float)($service['rate'] ?? $service['price'] ?? 0);
        $usdToCny = ExchangeRate::rate($config);
        $cost = max(0, $rate) * $usdToCny;

        if (($service['entityType'] ?? '') === 'smm' && (int)($config['smm_rate_per_1000'] ?? 1) === 1) {
            $cost /= 1000;
        }

        return round($cost, 4);
    }

    private function price(float $cost, array $config): float
    {
        $multiplier = $this->numberConfig($config, 'price_multiplier', 1.2);
        $plus = $this->numberConfig($config, 'price_plus', 0);
        $floor = $this->numberConfig($config, 'price_floor', 0.01);
        return round(max($floor, ($cost * $multiplier) + $plus), 2);
    }

    private function numberConfig(array $config, string $key, float $default): float
    {
        $value = trim((string)($config[$key] ?? ''));
        if ($value === '' || !is_numeric($value)) {
            return $default;
        }

        return max(0, (float)$value);
    }

    private function stockValue(mixed $value, array $config): int
    {
        $fallback = (int)($config['stock_value'] ?? 999999);
        if (!is_numeric($value)) {
            return min(max(0, $fallback), self::STOCK_MAX);
        }

        return min(max(0, (int)$value), self::STOCK_MAX);
    }

    private function description(array $service, array $config): string
    {
        $cost = $this->costPerUnit($service, $config);
        $price = $this->price($cost, $config);
        $entityType = (string)($service['entityType'] ?? '');
        $rateUnit = $entityType === 'smm' ? '美元 / 1000' : '美元 / 件';
        $rows = [
            '商品名称' => $this->cleanTitle((string)($service['name'] ?? $service['title'] ?? '')),
            '服务编号' => (string)($service['service'] ?? ''),
            '实体ID' => (string)($service['id'] ?? ''),
            '实体类型' => $entityType,
            '本地售价' => (string)$price . ' CNY / 件',
            '库存' => (string)$this->stockValue($service['stock'] ?? null, $config),
            '最低订购' => (string)($service['min'] ?? ''),
            '最高订购' => (string)($service['max'] ?? ''),
            '自动发货' => filter_var($service['autoDelivery'] ?? false, FILTER_VALIDATE_BOOLEAN) ? '是' : '否',
        ];

        $html = ['<table style="width:100%;border-collapse:collapse;margin:10px 0;">'];
        foreach ($rows as $label => $value) {
            if ($value === '') {
                continue;
            }
            $html[] = '<tr><td style="border:1px solid #eee;padding:8px;">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</td><td style="border:1px solid #eee;padding:8px;">' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</td></tr>';
        }
        if (!empty($service['description'])) {
            $html[] = '<tr><td style="border:1px solid #eee;padding:8px;">商品说明</td><td style="border:1px solid #eee;padding:8px;">' . $this->cleanHtml((string)$service['description']) . '</td></tr>';
        }
        $html[] = '</table>';

        return $this->limitBytes(implode('', $html), self::DESCRIPTION_MAX_BYTES);
    }

    private function cover(array $service, string $fallback): string
    {
        foreach ($this->imageCandidates($service) as $image) {
            $image = $this->normalizeImageUrl($image);
            if ($image !== '') {
                return $image;
            }
        }

        return $fallback;
    }

    private function imageCandidates(array $service): array
    {
        $keys = [
            'imageUrl', 'image_url', 'image',
            'thumbnailUrl', 'thumbnail_url', 'thumbnail', 'thumb',
            'coverUrl', 'cover_url', 'cover',
            'picture', 'photo', 'icon', 'logo',
        ];
        $candidates = [];
        foreach ($keys as $key) {
            if (isset($service[$key])) {
                $candidates[] = $service[$key];
            }
        }

        foreach (['images', 'imageUrls', 'image_urls', 'gallery', 'media'] as $key) {
            if (isset($service[$key])) {
                $candidates[] = $service[$key];
            }
        }

        return $this->flattenImageCandidates($candidates);
    }

    private function flattenImageCandidates(mixed $value): array
    {
        if (is_string($value) || is_numeric($value)) {
            return [(string)$value];
        }
        if (!is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && in_array($key, ['url', 'src', 'image', 'imageUrl', 'image_url', 'thumbnail', 'cover'], true)) {
                array_push($items, ...$this->flattenImageCandidates($item));
                continue;
            }
            if (is_array($item)) {
                foreach (['url', 'src', 'image', 'imageUrl', 'image_url', 'thumbnail', 'cover'] as $imageKey) {
                    if (isset($item[$imageKey])) {
                        array_push($items, ...$this->flattenImageCandidates($item[$imageKey]));
                    }
                }
                continue;
            }
            array_push($items, ...$this->flattenImageCandidates($item));
        }

        return $items;
    }

    private function normalizeImageUrl(string $image): string
    {
        $image = trim(html_entity_decode($image, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($image === '' || in_array(mb_strtolower($image, 'UTF-8'), ['null', 'undefined', 'false'], true)) {
            return '';
        }
        if (str_starts_with($image, 'data:')) {
            return '';
        }
        if (str_starts_with($image, '//')) {
            return 'https:' . $image;
        }
        if (str_starts_with($image, '/uploads/')) {
            return 'https://hstockplus.net' . $image;
        }
        if (str_starts_with($image, '/')) {
            return 'https://hstockplus.com' . $image;
        }
        if (!preg_match('#^https?://#i', $image)) {
            return '';
        }

        return str_replace(' ', '%20', $image);
    }

    private function serviceConfig(array $service): string
    {
        $path = $this->categoryPath($service);
        $category = $this->fieldValue($service, ['category']);
        $subcategory = $this->fieldValue($service, ['subcategoryName', 'subcategory_name', 'subCategoryName', 'subcategory', 'sub_category']);
        $subcategoryId = $this->fieldValue($service, ['subcategoryId', 'subcategory_id', 'subCategoryId']);

        return Ini::toConfig([
            'hstockplus_specs' => [
                'id' => (string)($service['id'] ?? ''),
                'service' => (string)($service['service'] ?? ''),
                'entity_type' => (string)($service['entityType'] ?? ''),
                'rate' => (string)($service['rate'] ?? $service['price'] ?? ''),
                'stock' => (string)($service['stock'] ?? ''),
                'minimum_order' => (string)max(0, (int)($service['min'] ?? 0)),
                'maximum_order' => (string)max(0, (int)($service['max'] ?? 0)),
                'category' => $this->configValue($category),
                'top_category' => $this->configValue((string)($path[0] ?? '')),
                'subcategory' => $this->configValue($subcategory),
                'subcategory_name' => $this->configValue((string)($path[1] ?? '')),
                'subcategory_id' => $this->configValue($subcategoryId),
                'auto_delivery' => filter_var($service['autoDelivery'] ?? false, FILTER_VALIDATE_BOOLEAN) ? '1' : '0',
            ],
        ]);
    }

    private function configValue(string $value): string
    {
        return str_replace(["\r", "\n", '='], [' ', ' ', '-'], trim($value));
    }

    private function fieldValue(array $row, array $keys): string
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $row)) {
                continue;
            }

            $value = $this->scalarFieldValue($row[$key]);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function scalarFieldValue(mixed $value): string
    {
        if (is_string($value) || is_numeric($value)) {
            return trim((string)$value);
        }
        if (!is_array($value)) {
            return '';
        }

        foreach (['name', 'title', 'category', 'label', 'id', '_id', 'value'] as $key) {
            if (!array_key_exists($key, $value)) {
                continue;
            }

            $nested = $this->scalarFieldValue($value[$key]);
            if ($nested !== '') {
                return $nested;
            }
        }

        return '';
    }

    private function cleanText(string $value): string
    {
        return trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function cleanTitle(string $value): string
    {
        $value = $this->cleanText($value);
        $value = preg_replace('/^(?:HStockPlus|HStock\\s*Plus)\\s*[-_：:|]*\\s*/iu', '', $value) ?? $value;
        return trim($value);
    }

    private function cleanHtml(string $value): string
    {
        $value = preg_replace('/<!--.*?-->/su', '', $value) ?? $value;
        $value = preg_replace('/<\\/?(?:html|head|body|style|script|meta|link|iframe|object|embed|svg|canvas|video|audio|source)[^>]*>/iu', '', $value) ?? $value;
        $value = preg_replace('/<img\\b[^>]*>/iu', '', $value) ?? $value;
        $value = preg_replace('/\\s(?:on\\w+|style|class|id|srcset|data-[\\w-]+)=(".*?"|\'.*?\'|[^\\s>]*)/isu', '', $value) ?? $value;
        $value = preg_replace('/\\s(?:href|src)=("|\')\\s*(?:javascript|data):.*?\\1/isu', '', $value) ?? $value;
        $value = preg_replace('/\\s{2,}/u', ' ', $value) ?? $value;
        return trim($value);
    }

    private function limitBytes(string $value, int $maxBytes): string
    {
        if (strlen($value) <= $maxBytes) {
            return $value;
        }

        $suffix = '<p>商品说明内容较长，已自动截断。</p>';
        $limit = max(0, $maxBytes - strlen($suffix));
        $trimmed = mb_strcut($value, 0, $limit, 'UTF-8');
        $trimmed = preg_replace('/<[^>]*$/u', '', $trimmed) ?? $trimmed;

        return trim($trimmed) . $suffix;
    }

    private function parseMapping(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        $mapping = json_decode($raw, true);
        return is_array($mapping) ? $mapping : [];
    }

    private function normalizeApiUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return 'https://hstockplus.com/api/v2';
        }

        if (str_ends_with($url, '/api')) {
            return $url . '/v2';
        }

        return $url;
    }

    private function isList(array $value): bool
    {
        return array_keys($value) === range(0, count($value) - 1);
    }

    private function actionName(int $action): string
    {
        return match ($action) {
            1 => '更新上游商品分类',
            2 => '更新上游商品',
            3 => '更新上游商品和价格',
            4 => '批量重算价格浮动',
            default => '同步',
        };
    }
}
