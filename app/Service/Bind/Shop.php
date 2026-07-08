<?php
declare(strict_types=1);

namespace App\Service\Bind;

use App\Consts\Hook;
use App\Model\Business;
use App\Model\Card;
use App\Model\Category;
use App\Model\Commodity;
use App\Model\Config;
use App\Model\User;
use App\Model\UserCategory;
use App\Model\UserCommodity;
use App\Model\UserGroup;
use App\Service\Shared;
use App\Util\Client;
use App\Util\Ini;
use App\Util\Tree;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Kernel\Annotation\Inject;
use Kernel\Exception\JSONException;
use Kernel\Exception\RuntimeException;
use Kernel\Plugin\Entity\Stock;
use Kernel\Util\Decimal;

class Shop implements \App\Service\Shop
{
    private const SOURCE_PREFIX = [
        'default' => '',
        'hstockplus' => 'HSP_',
        'fansgurus' => 'FG_',
    ];

    #[Inject]
    private Shared $shared;

    #[Inject]
    private \App\Service\Order $order;

    /**
     * @param UserGroup|null $group
     * @return array
     * @throws RuntimeException
     */
    public function getCategory(?UserGroup $group, ?string $source = null): array
    {
        $source = self::normalizeSource($source);
        $sourceCategoryIds = $this->sourceVisibleCategoryIds($source);
        $category = Category::query();
        if ($source) {
            $category = $sourceCategoryIds
                ? $category->whereIn("id", $sourceCategoryIds)
                : $category->whereRaw("1=0");
        } else {
            $category = $category->where("status", 1);
        }
        $category = $category->orderBy("sort", "asc");

        $bus = Business::get();
        $userCategoryMap = []; //自定义名称的MAP
        $master = true;

        if ($bus) {
            $master = false;
            //商家
            if ($bus->master_display == 0) {
                $category = $category->where("owner", $bus->user_id);
            } else {
                //查询出所有不显示的ID
                $userCategory = UserCategory::query()->where("user_id", $bus->user_id)->get();
                //隐藏的分类ID
                $hideCategory = [];

                foreach ($userCategory as $userCate) {
                    if ($userCate->status == 0) {
                        $hideCategory[] = $userCate->category_id;
                    } else {
                        $userCategoryMap[$userCate->category_id] = $userCate;
                    }
                }

                $category = $category->whereNotIn("id", $hideCategory)->whereRaw("(`owner`=0 or `owner`={$bus->user_id})");
            }
        } else {
            //主站
            if (Config::get("substation_display") == 1) {
                //显示商家
                $list = (array)json_decode(Config::get("substation_display_list"), true);
                $let = "(`owner`=0 or ";
                foreach ($list as $userId) {
                    $let .= "`owner`={$userId} or ";
                }
                $let = trim(trim($let), "or") . ")";
                $category = $category->whereRaw($let);
            } else {
                $category = $category->where("owner", 0);
            }
        }
        //拿到最终的分类数据
        $category = $category->get();

        foreach ($category as $index => $item) {

            $levelConfig = $item->getLevelConfig($group);
            if ($item->hide == 1 && (!$levelConfig || !isset($levelConfig['show']) || (int)$levelConfig['show'] != 1)) {
                unset($category[$index]);
                continue;
            }

            if (isset($userCategoryMap[$item->id])) {
                $var = $userCategoryMap[$item->id];
                if ($var->name) {
                    $category[$index]['name'] = $var->name;
                }
            }

            if (isset($category[$index]['name'])) {
                $category[$index]['name'] = $this->cleanCategoryName((string)$category[$index]['name']);
            }

            if (!$item->icon) {
                $category[$index]['icon'] = '/favicon.ico';
            }
        }

        $array = $category->toArray();
        $array = array_values($array);
        $this->hydrateCategoryTreeMeta($array, $source);
        $this->normalizeSourceTopCategories($array, $source);

        $commodityRecommend = Config::get("commodity_recommend");
        if ($commodityRecommend == 1 && $master) {
            $recommend = Commodity::query()->where("status", 1)->where("recommend", 1);
            $this->applySourceFilter($recommend, $source);
            array_unshift($array, [
                "id" => 'recommend',
                "name" => Config::get("commodity_name"),
                "sort" => 1,
                "create_time" => "-",
                "owner" => 0,
                "pid" => null,
                "icon" => "/assets/static/images/recommend.png",
                "status" => 1,
                "hide" => 0,
                "user_level_config" => null,
                "commodity_count" => $recommend->count(),
                "subcategories" => [],
            ]);
        }

        return $array;
    }

    private function sourceVisibleCategoryIds(?string $source): array
    {
        return $this->getSourceVisibleCategoryIds($source);
    }

    private function normalizeSourceTopCategories(array &$categories, ?string $source): void
    {
        if (!in_array($source, ['fansgurus', 'hstockplus'], true)) {
            return;
        }

        $sortMap = array_flip($source === 'hstockplus' ? $this->hstockplusPlatformCategories() : $this->fansGurusPlatformCategories());
        foreach ($categories as &$category) {
            if (!is_numeric($category['id'] ?? null)) {
                continue;
            }

            $pid = (int)($category['pid'] ?? 0);
            if ($pid > 0) {
                continue;
            }

            $name = $this->cleanCategoryName((string)($category['name'] ?? ''));
            $category['name'] = $name;
            if (isset($sortMap[$name])) {
                $category['sort'] = ($sortMap[$name] + 1) * 10;
                $category['icon'] = $source === 'hstockplus' ? $this->hstockplusCategoryIcon($name) : $this->fansGurusCategoryIcon($name);
            }
        }
        unset($category);

        if ($source === 'hstockplus') {
            $enabled = array_flip($this->hstockplusEnabledTopCategories());
            $existing = [];
            foreach ($categories as $category) {
                if ((int)($category['pid'] ?? 0) === 0) {
                    $existing[$this->cleanCategoryName((string)($category['name'] ?? ''))] = true;
                }
            }

            foreach ($this->hstockplusPlatformCategories() as $name) {
                if (!isset($enabled[$name])) {
                    continue;
                }
                if (isset($existing[$name])) {
                    continue;
                }
                $categories[] = [
                    'id' => 'hstockplus-' . md5($name),
                    'name' => $name,
                    'sort' => ($sortMap[$name] + 1) * 10,
                    'create_time' => '-',
                    'owner' => 0,
                    'pid' => null,
                    'icon' => $this->hstockplusCategoryIcon($name),
                    'status' => 1,
                    'hide' => 0,
                    'user_level_config' => null,
                    'commodity_count' => 0,
                    'subcategories' => [],
                    'children' => [],
                ];
            }
        }

        usort($categories, static function (array $a, array $b): int {
            $aPid = (int)($a['pid'] ?? 0);
            $bPid = (int)($b['pid'] ?? 0);
            if ($aPid !== $bPid) {
                return $aPid <=> $bPid;
            }
            return ((int)($a['sort'] ?? 9999) <=> (int)($b['sort'] ?? 9999)) ?: ((int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0));
        });
    }

    private function fansGurusPlatformCategories(): array
    {
        return [
            'Instagram',
            'TikTok',
            'X/Twitter',
            'YouTube',
            'Telegram',
            'Facebook',
            'VK',
            'Spotify',
            'Discord',
            'Twitch',
            'Reddit',
            'LinkedIn',
            'Crypto',
            'Binance',
            'GitHub',
            'Quora',
            'WhatsApp',
            'LINE',
            'Google/Maps',
            'Trustpilot',
            'Website Traffic',
            'Apple/App Store',
            'Android/Google Play',
            '其他服务',
        ];
    }

    private function hstockplusPlatformCategories(): array
    {
        return [
            '账户',
            '电子邮件',
            '增长服务',
            '代理服务',
            '短信验证',
            '评论',
        ];
    }

    private function hstockplusEnabledTopCategories(): array
    {
        $configFile = BASE_PATH . '/app/Plugin/HStockPlus/Config/Config.php';
        $config = is_file($configFile) ? require $configFile : [];
        $raw = is_array($config) ? (string)($config['enabled_top_categories'] ?? '账户') : '账户';
        $items = preg_split('/[,，|\\s]+/u', $raw) ?: [];
        $enabled = array_values(array_filter(array_map([$this, 'cleanCategoryName'], $items), static fn(string $name): bool => $name !== ''));

        return $enabled ?: ['账户'];
    }

    private function fansGurusCategoryIcon(string $category): string
    {
        $icons = [
            'Instagram' => 'instagram',
            'TikTok' => 'tiktok',
            'X/Twitter' => 'twitter',
            'YouTube' => 'youtube',
            'Telegram' => 'telegram',
            'Facebook' => 'facebook',
            'VK' => 'vk',
            'Spotify' => 'spotify',
            'Discord' => 'discord',
            'Twitch' => 'twitch',
            'Reddit' => 'reddit',
            'LinkedIn' => 'linkedin',
            'Crypto' => 'crypto',
            'Binance' => 'binance',
            'GitHub' => 'github',
            'Quora' => 'quora',
            'WhatsApp' => 'whatsapp',
            'LINE' => 'line',
            'Google/Maps' => 'google-maps',
            'Trustpilot' => 'trustpilot',
            'Website Traffic' => 'website-traffic',
            'Apple/App Store' => 'apple',
            'Android/Google Play' => 'android',
            '其他服务' => 'other',
        ];

        return '/app/Plugin/FansGurus/Assets/icons/' . ($icons[$category] ?? 'other') . '.svg';
    }

    private function hstockplusCategoryIcon(string $category): string
    {
        $icons = [
            '账户' => 'other',
            '电子邮件' => 'other',
            '增长服务' => 'website-traffic',
            '代理服务' => 'website-traffic',
            '短信验证' => 'other',
            '评论' => 'other',
        ];

        return '/app/Plugin/HStockPlus/Assets/icons/' . ($icons[$category] ?? 'other') . '.svg';
    }

    public function getSourceVisibleCategoryIds(?string $source = null): array
    {
        $source = self::normalizeSource($source);
        if (!$source) {
            return [];
        }

        $query = Commodity::query()
            ->where('status', 1)
            ->whereNotNull('category_id')
            ->distinct();
        self::applySourceFilter($query, $source);
        $ids = $query
            ->pluck('category_id')
            ->filter()
            ->map(static fn($id): int => (int)$id)
            ->values()
            ->all();

        $frontier = $ids;
        while ($frontier) {
            $parents = Category::query()
                ->whereIn('id', $frontier)
                ->pluck('pid')
                ->filter()
                ->map(static fn($id): int => (int)$id)
                ->all();
            $parents = array_values(array_diff($parents, $ids));
            if (!$parents) {
                break;
            }
            array_push($ids, ...$parents);
            $frontier = $parents;
        }

        if ($source === 'hstockplus') {
            $ids = $this->filterHStockPlusEnabledCategoryIds($ids);
        }

        return array_values(array_unique($ids));
    }

    private function filterHStockPlusEnabledCategoryIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return [];
        }

        $rows = Category::query()->whereIn('id', $ids)->get(['id', 'pid', 'name']);
        $byId = [];
        $frontier = [];
        foreach ($rows as $row) {
            $id = (int)$row->id;
            $byId[$id] = ['id' => $id, 'pid' => (int)$row->pid, 'name' => (string)$row->name];
            if ((int)$row->pid > 0 && !in_array((int)$row->pid, $ids, true)) {
                $frontier[] = (int)$row->pid;
            }
        }

        while ($frontier) {
            $parents = Category::query()->whereIn('id', array_values(array_unique($frontier)))->get(['id', 'pid', 'name']);
            $frontier = [];
            foreach ($parents as $parent) {
                $id = (int)$parent->id;
                if (isset($byId[$id])) {
                    continue;
                }
                $byId[$id] = ['id' => $id, 'pid' => (int)$parent->pid, 'name' => (string)$parent->name];
                if ((int)$parent->pid > 0) {
                    $frontier[] = (int)$parent->pid;
                }
            }
        }

        $enabled = array_flip($this->hstockplusEnabledTopCategories());
        return array_values(array_filter($ids, function (int $id) use ($enabled, $byId): bool {
            $current = $byId[$id] ?? null;
            while ($current && (int)$current['pid'] > 0 && isset($byId[(int)$current['pid']])) {
                $current = $byId[(int)$current['pid']];
            }

            return $current && isset($enabled[$this->cleanCategoryName((string)$current['name'])]);
        }));
    }

    public static function normalizeSource(?string $source): ?string
    {
        $source = strtolower(trim((string)$source));
        return array_key_exists($source, self::SOURCE_PREFIX) ? $source : null;
    }

    public static function sourcePrefix(?string $source): ?string
    {
        $source = self::normalizeSource($source);
        return $source && self::SOURCE_PREFIX[$source] !== '' ? self::SOURCE_PREFIX[$source] : null;
    }

    public static function applySourceFilter(Builder $builder, ?string $source): void
    {
        $source = self::normalizeSource($source);
        if ($source === 'default') {
            foreach (self::SOURCE_PREFIX as $prefix) {
                if ($prefix === '') {
                    continue;
                }
                $builder->where('code', 'not like', $prefix . '%');
            }
            return;
        }

        $prefix = self::sourcePrefix($source);
        if ($prefix) {
            $builder->where('code', 'like', $prefix . '%');
        }
    }

    public static function sourceFromCommodityConfig(array|string|null $config): ?string
    {
        $array = is_array($config) ? $config : self::parseCommodityConfig((string)$config);
        if (isset($array['hstockplus_specs'])) {
            return 'hstockplus';
        }
        if (isset($array['fansgurus_specs'])) {
            return 'fansgurus';
        }
        return null;
    }

    public static function sourceSubcategoryValue(array|string|null $config, ?string $source = null): string
    {
        $array = is_array($config) ? $config : self::parseCommodityConfig((string)$config);
        $source = self::normalizeSource($source) ?: self::sourceFromCommodityConfig($array);
        $spec = $source === 'hstockplus' ? ($array['hstockplus_specs'] ?? []) : ($array['fansgurus_specs'] ?? []);
        if (!is_array($spec)) {
            return '';
        }

        $keys = $source === 'hstockplus'
            ? ['subcategory_name', 'subcategory']
            : ['type'];

        foreach ($keys as $key) {
            $value = self::cleanSourceLabel((string)($spec[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        $category = self::cleanSourceLabel((string)($spec['category'] ?? ''));
        $categoryParts = self::splitSourceCategoryLabel($category);
        if (count($categoryParts) >= 2) {
            return $categoryParts[1];
        }
        if ($source === 'fansgurus' && $category !== '') {
            return $category;
        }

        $value = trim((string)($spec['subcategory_id'] ?? ''));
        return $value;
    }

    private static function splitSourceCategoryLabel(string $value): array
    {
        $value = self::cleanSourceLabel($value);
        if ($value === '') {
            return [];
        }

        $parts = preg_split('/\s*(?:[-_\/|>＞:：]+)\s*/u', $value) ?: [];
        $parts = array_values(array_filter(array_map([self::class, 'cleanSourceLabel'], $parts), static fn(string $part): bool => $part !== ''));
        if (count($parts) <= 1) {
            return $parts;
        }

        return [$parts[0], implode('-', array_slice($parts, 1))];
    }

    private static function cleanSourceLabel(string $value): string
    {
        return trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private static function parseCommodityConfig(string $config): array
    {
        if (trim($config) === '') {
            return [];
        }
        try {
            return Ini::toArray($config);
        } catch (\Throwable) {
            return [];
        }
    }

    private function hydrateCategoryTreeMeta(array &$categories, ?string $source): void
    {
        if (!$categories) {
            return;
        }

        $childrenByParent = [];
        $categoryIds = [];
        foreach ($categories as $category) {
            if (!is_numeric($category['id'])) {
                continue;
            }
            $id = (int)$category['id'];
            $categoryIds[] = $id;
            $pid = (int)($category['pid'] ?? 0);
            if ($pid > 0) {
                $childrenByParent[$pid][] = $id;
            }
        }

        $directCounts = [];
        $descendantCounts = [];
        if ($categoryIds) {
            $countQuery = Commodity::query()
                ->where('status', 1)
                ->whereIn('category_id', $categoryIds)
                ->selectRaw('category_id, count(*) as aggregate')
                ->groupBy('category_id');
            self::applySourceFilter($countQuery, $source);
            $directCounts = $countQuery->pluck('aggregate', 'category_id')->all();
        }
        $fallbackSubcategories = $this->fallbackSourceSubcategories($categoryIds, $source);

        $categoryById = [];
        foreach ($categories as $category) {
            if (is_numeric($category['id'])) {
                $categoryById[(int)$category['id']] = $category;
            }
        }

        foreach ($categories as &$category) {
            if (!is_numeric($category['id'])) {
                $category['subcategories'] = [];
                continue;
            }

            $id = (int)$category['id'];
            $category['commodity_count'] = $this->categoryCommodityCount($id, $childrenByParent, $directCounts, $descendantCounts);

            $subs = [];
            foreach ($childrenByParent[$id] ?? [] as $childId) {
                $count = $this->categoryCommodityCount((int)$childId, $childrenByParent, $directCounts, $descendantCounts);
                if ($source && $count <= 0) {
                    continue;
                }
                $child = $categoryById[$childId] ?? null;
                if (!$child) {
                    continue;
                }
                $subs[] = [
                    'id' => $childId,
                    'name' => $this->cleanCategoryName((string)$child['name']),
                    'value' => (string)$childId,
                    'count' => $count,
                ];
            }
            if (!$subs && isset($fallbackSubcategories[$id])) {
                $subs = $fallbackSubcategories[$id];
            }
            $category['subcategories'] = $subs;
        }
        unset($category);

        if ($source) {
            $visibleIds = [];
            foreach ($categories as $category) {
                if (!is_numeric($category['id'])) {
                    continue;
                }
                if ((int)($category['commodity_count'] ?? 0) > 0) {
                    $visibleIds[(int)$category['id']] = true;
                    $pid = (int)($category['pid'] ?? 0);
                    while ($pid > 0 && isset($categoryById[$pid])) {
                        $visibleIds[$pid] = true;
                        $pid = (int)($categoryById[$pid]['pid'] ?? 0);
                    }
                }
            }
            $categories = array_values(array_filter($categories, static fn(array $category): bool => !is_numeric($category['id']) || isset($visibleIds[(int)$category['id']])));
        }

        if ($source === 'hstockplus') {
            $categories = $this->filterHStockPlusEnabledTopCategories($categories);
        }
    }

    private function filterHStockPlusEnabledTopCategories(array $categories): array
    {
        $enabled = array_flip($this->hstockplusEnabledTopCategories());
        $byId = [];
        foreach ($categories as $category) {
            if (is_numeric($category['id'] ?? null)) {
                $byId[(int)$category['id']] = $category;
            }
        }

        return array_values(array_filter($categories, function (array $category) use ($enabled, $byId): bool {
            $current = $category;
            while (is_numeric($current['pid'] ?? null) && (int)$current['pid'] > 0 && isset($byId[(int)$current['pid']])) {
                $current = $byId[(int)$current['pid']];
            }

            return isset($enabled[$this->cleanCategoryName((string)($current['name'] ?? ''))]);
        }));
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

    private function categoryCommodityCount(int $categoryId, array $childrenByParent, array $directCounts, array &$cache): int
    {
        if (isset($cache[$categoryId])) {
            return $cache[$categoryId];
        }

        $count = (int)($directCounts[$categoryId] ?? 0);
        foreach ($childrenByParent[$categoryId] ?? [] as $childId) {
            $count += $this->categoryCommodityCount((int)$childId, $childrenByParent, $directCounts, $cache);
        }

        return $cache[$categoryId] = $count;
    }

    public function normalizeCategoryTree(array $category): array
    {
        foreach ($category as &$item) {
            $children = isset($item['children']) && is_array($item['children']) ? $this->normalizeCategoryTree($item['children']) : [];
            $item['children'] = $children;

            $subcategories = [];
            foreach ($children as $child) {
                if (!is_numeric($child['id'] ?? null)) {
                    continue;
                }
                $subcategories[] = [
                    'id' => (int)$child['id'],
                    'name' => $this->cleanCategoryName((string)($child['name'] ?? '')),
                    'value' => (string)$child['id'],
                    'count' => (int)($child['commodity_count'] ?? 0),
                ];
            }

            if (!$subcategories && isset($item['subcategories']) && is_array($item['subcategories'])) {
                $subcategories = $item['subcategories'];
            }
            $item['subcategories'] = $subcategories;
        }
        unset($item);

        return $category;
    }

    private function fallbackSourceSubcategories(array $categoryIds, ?string $source): array
    {
        if (!$source || !$categoryIds) {
            return [];
        }

        $query = Commodity::query()
            ->where('status', 1)
            ->whereIn('category_id', $categoryIds)
            ->whereNotNull('config');
        self::applySourceFilter($query, $source);

        $subcategories = [];
        foreach ($query->get(['category_id', 'config']) as $row) {
            $value = self::sourceSubcategoryValue((string)$row->config, $source);
            if ($value === '') {
                continue;
            }
            $categoryId = (int)$row->category_id;
            $key = md5($value);
            $subcategories[$categoryId][$key] ??= [
                'id' => $key,
                'name' => $value,
                'value' => rawurlencode($value),
                'count' => 0,
            ];
            $subcategories[$categoryId][$key]['count']++;
        }

        foreach ($subcategories as &$items) {
            $items = array_values($items);
            usort($items, static fn(array $a, array $b) => $b['count'] <=> $a['count'] ?: strcmp($a['name'], $b['name']));
        }
        unset($items);

        return $subcategories;
    }

    private function appendSourceSubcategories(array &$categories, ?string $source): void
    {
        if (!$source || !$categories) {
            foreach ($categories as &$category) {
                $category['subcategories'] = [];
            }
            unset($category);
            return;
        }

        $categoryIds = array_values(array_filter(array_map(static fn(array $category) => is_numeric($category['id']) ? (int)$category['id'] : null, $categories)));
        if (!$categoryIds) {
            return;
        }

        $query = Commodity::query()
            ->where("status", 1)
            ->whereIn("category_id", $categoryIds)
            ->whereNotNull("config");
        self::applySourceFilter($query, $source);
        $rows = $query->get(['category_id', 'config']);
        $subcategories = [];

        foreach ($rows as $row) {
            $value = self::sourceSubcategoryValue((string)$row->config, $source);
            if ($value === '') {
                continue;
            }
            $key = md5($value);
            $categoryId = (int)$row->category_id;
            $subcategories[$categoryId][$key] ??= [
                'id' => $key,
                'name' => $value,
                'value' => rawurlencode($value),
                'count' => 0,
            ];
            $subcategories[$categoryId][$key]['count']++;
        }

        foreach ($categories as &$category) {
            $items = array_values($subcategories[(int)$category['id']] ?? []);
            usort($items, static fn(array $a, array $b) => $b['count'] <=> $a['count'] ?: strcmp($a['name'], $b['name']));
            $category['subcategories'] = $items;
        }
        unset($category);
    }

    private function cleanCategoryName(string $name): string
    {
        $name = trim(html_entity_decode(strip_tags($name), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $name = preg_replace('/^(?:HStockPlus|HStock\s*Plus)\s*[-_：:|]*\s*/iu', '', $name) ?? $name;
        return trim($name);
    }

    /**
     * @param int|string $commodityId
     * @param User|null $user
     * @param UserGroup|null $group
     * @return array
     * @throws JSONException
     * @throws RuntimeException
     */
    public function getItem(int|string $commodityId, ?User $user = null, ?UserGroup $group = null): array
    {

        $commodity = Commodity::query()->with(['owner' => function (Relation $relation) {
            $relation->select(["id", "username", "avatar"]);
        }])
            ->select(["id", "name", "description",
                "only_user", "purchase_count", "category_id", "cover", "price", "user_price",
                "status", "owner", "delivery_way", "contact_type", "password_status", "level_price",
                "level_disable", "coupon", "shared_id", "shared_code", "shared_premium", "shared_premium_type", "seckill_status",
                "seckill_start_time", "seckill_end_time", "draft_status", "draft_premium", "inventory_hidden",
                "widget", "minimum", "maximum", "shared_sync", "config", "stock", "code", "shared_amount_sync", "shared_config_sync"])
            ->withCount(['order as order_sold' => function (Builder $relation) {
                $relation->where("delivery_status", 1);
            }]);

        if (is_int($commodityId)) {
            $commodity = $commodity->find($commodityId);
        } else {
            $commodity = $commodity->where("code", $commodityId)->first();
        }

        if (!$commodity) {
            throw new JSONException("商品不存在");
        }

        if ($commodity->status != 1) {
            throw new JSONException("该商品暂未上架");
        }

        /**
         * @var Shared $shared
         */
        $shared = \App\Model\Shared::query()->find($commodity->shared_id);

        if ($shared) {
            //远端同步
            if ($commodity->shared_sync == 1) {
                /**
                 * @var Commodity $new
                 */
                $new = Commodity::query()->find($commodity->id);

                $remoteItem = $this->shared->item($shared, $new->shared_code);

                $base = $this->shared->AdjustmentPrice(Ini::toConfig($remoteItem['config'] ?: []), (string)$remoteItem['price'], (string)$remoteItem['user_price'], $new->shared_premium_type, $new->shared_premium);

                $_config = $remoteItem['config'] ?: [];

                if (!empty($_config['sku'])) {
                    $base['config']['sku_cost'] = $_config['sku'];
                }

                if (!empty($_config['category'])) {
                    $base['config']['category_cost'] = $_config['category'];
                }

                if ($commodity->shared_amount_sync === 1) {
                    $commodity->price = $new->price = $base['price'];
                    $commodity->user_price = $new->user_price = $base['user_price'];
                }


                if ($commodity->shared_config_sync === 1) {
                    $commodity->config = $new->config = Ini::toConfig($base['config']);
                }

                $commodity->draft_status = $new->draft_status = $remoteItem['draft_status'];
                $commodity->draft_premium = $new->draft_premium = $remoteItem['draft_premium'] > 0 ? $this->shared->AdjustmentAmount($new->shared_premium_type, $new->shared_premium, $remoteItem['draft_premium']) : 0;
                $commodity->seckill_status = $new->seckill_status = $remoteItem['seckill_status'];
                $commodity->seckill_start_time = $new->seckill_start_time = $remoteItem['seckill_start_time'];
                $commodity->seckill_end_time = $new->seckill_end_time = $remoteItem['seckill_end_time'];
                $commodity->widget = $new->widget = is_array($remoteItem['widget']) ? json_encode($remoteItem['widget']) : $remoteItem['widget'];
                $commodity->minimum = $new->minimum = $remoteItem['minimum'];
                $commodity->maximum = $new->maximum = $remoteItem['maximum'];
                $commodity->stock = $new->stock = $remoteItem['stock'];
                $commodity->contact_type = $new->contact_type = $remoteItem['contact_type'];

                $new->save();
            }
        } else if ($commodity->delivery_way == 0) {
            $commodity->stock = Card::query()->where("commodity_id", $commodity->id)->where("status", 0)->count();

        }

        //解析商品配置
        $this->order->parseConfig($commodity, $group);


        //处理分站
        $this->substationPriceIncrease($commodity);

        $commodity->service_url = Config::get("service_url");
        $commodity->service_qq = Config::get("service_qq");

        if ($commodity->draft_status == 1 && $commodity->draft_premium > 0 && $commodity->level_disable != 1) {
            $commodity->draft_premium = $this->order->getValuationPrice($commodity->id, $commodity->draft_premium, $group);
        }

        $array = $commodity->toArray();

        if ($array["owner"]) {
            $business = Business::query()->where("user_id", $array["owner"]['id'])->first();
            if ($business) {
                $array['service_url'] = $business->service_url;
                $array['service_qq'] = $business->service_qq;
            }
        }

        if (!$array['cover']) {
            $array['cover'] = "/favicon.ico";
        }

        $array['share_url'] = Client::getUrl() . "/item/{$array['id']}";
        $array['login'] = (bool)$user;
        if ($array['login']) {
            $array['share_url'] .= "?from={$user->id}";
        }

        //获取网站是否需要验证码
        $array['trade_captcha'] = (int)Config::get("trade_verification");

        if ($commodity->widget) {
            $array['widget'] = json_decode($commodity->widget, true);
        }

        return $array;
    }

    /**
     * @param int|string|null $stock
     * @return string
     */
    public function getHideStock(int|string|null $stock): string
    {
        $stock = (int)$stock;
        return match (true) {
            $stock <= 0 => "已售罄",
            $stock <= 5 => "即将售罄",
            $stock <= 20 => "一般",
            $stock <= 100 => "充足",
            default => "非常多"
        };
    }

    /**
     * @param int|string|null $stock
     * @return int
     */
    public function getStockState(int|string|null $stock): int
    {
        $stock = (int)$stock;
        return match (true) {
            $stock <= 0 => 0,
            $stock <= 5 => 1,
            $stock <= 20 => 2,
            $stock <= 100 => 3,
            default => 4
        };
    }

    /**
     * @param int|Commodity|string $commodity
     * @param string|null $race
     * @param array|null $sku
     * @return string
     * @throws JSONException
     */
    public function getItemStock(int|Commodity|string $commodity, ?string $race = null, ?array $sku = []): string
    {
        if (is_int($commodity)) {
            $commodity = Commodity::with(['shared'])->find($commodity);
        } elseif (is_string($commodity)) {
            $commodity = Commodity::with(['shared'])->where("code", $commodity)->first();
        }

        if (!$commodity) throw new JSONException("商品不存在");

        if (($hook = \hook(Hook::SERVICE_SHOP_GET_ITEM_STOCK, $commodity, $race, $sku)) instanceof Stock) return $hook->getStock();

        //对接商品
        if ($commodity->shared) {
            return $this->getSharedStock($commodity, $race, $sku);
        } else if ($commodity->delivery_way == 0) {
            //库存
            $card = Card::query()->where("commodity_id", $commodity->id)->where("status", 0);
            if ($race) $card = $card->where("race", $race);
            if (!empty($sku)) {
                foreach ($sku as $k => $v) {
                    $card = $card->where("sku->{$k}", $v);
                }
            }
            return (string)$card->count();
        }
        return (string)$commodity->stock;
    }


    /**
     * @param int $id
     * @param string|null $race
     * @param array|null $sku
     * @return string
     */
    public function getSharedStockHash(int $id, ?string $race = null, ?array $sku = []): string
    {
        return md5($id . $race . json_encode($sku ?: []));
    }


    /**
     * @param int|Commodity $commodity
     * @param string|null $race
     * @param array|null $sku
     * @return void
     * @throws JSONException
     */
    public function updateSharedStock(int|Commodity $commodity, ?string $race = null, ?array $sku = []): void
    {
        if (is_int($commodity)) {
            $commodity = Commodity::query()->find($commodity);
        }
        if (!$commodity) throw new JSONException("商品不存在");
        $hash = $this->getSharedStockHash($commodity->id, $race, $sku);
        $stock = is_array($commodity->shared_stock) ? $commodity->shared_stock : [];
        unset($stock[$hash]);
        Commodity::query()->where("id", $commodity->id)->update(["shared_stock" => $stock]);
    }

    /**
     * @param int|Commodity $commodity
     * @param string|null $race
     * @param array|null $sku
     * @return string|null
     * @throws JSONException
     */
    public function getSharedStock(int|Commodity $commodity, ?string $race = null, ?array $sku = []): string|null
    {
        if (is_int($commodity)) {
            $commodity = Commodity::query()->find($commodity);
        }
        if (!$commodity) throw new JSONException("商品不存在");
        $hash = $this->getSharedStockHash($commodity->id, $race, $sku);

        if (!is_array($commodity->shared_stock) || !isset($commodity->shared_stock[$hash])) {
            $stock = $this->shared->getItemStock((clone $commodity), $commodity->shared, $commodity->shared_code, $race, $sku);
            $array = is_array($commodity->shared_stock) ? $commodity->shared_stock : [];
            $array[$hash] = $stock;
            Commodity::query()->where("id", $commodity->id)->update(["shared_stock" => $array]);
            return $stock;
        }

        return $commodity->shared_stock[$hash];
    }


    /**
     * @param Commodity|int|string $commodity
     * @param int $cardId
     * @return array
     * @throws JSONException
     */
    public function getDraft(Commodity|int|string $commodity, int $cardId): array
    {
        if (is_int($commodity)) {
            $commodity = Commodity::query()->find($commodity);
        }
        if (!$commodity) throw new JSONException("商品不存在");


        $card = Card::query()->where("commodity_id", $commodity->id)->where("id", $cardId)->first();
        if (!$card) {
            throw new JSONException("预选的宝贝不存在");
        }

        if ($commodity->id != $card->commodity_id) {
            throw new JSONException("此预告信息不属于此商品");
        }

        if ($card->status != 0) {
            throw new JSONException("此宝贝已被他人抢走");
        }

        return ["draft_premium" => $card->draft_premium, "cost" => $card->cost];
    }


    /**
     * @param Commodity $commodity
     * @return void
     */
    public function substationPriceIncrease(Commodity &$commodity): void
    {
        $business = Business::get();

        if (!$business) {
            return;
        }

        /**
         * @var UserCommodity $userCommodity
         */
        $userCommodity = UserCommodity::query()->where("user_id", $business->user_id)->where("commodity_id", $commodity->id)->first();

        if (!$userCommodity) {
            return;
        }

        if ($userCommodity->name) {
            $commodity->name = $userCommodity->name;
        }

        $config = $commodity->config ?: [];

        if ($userCommodity->premium > 0) {

            $commodity->price = (new Decimal($commodity->price))->mul($userCommodity->premium / 100)->add($commodity->price)->getAmount();
            $commodity->user_price = (new Decimal($commodity->user_price))->mul($userCommodity->premium / 100)->add($commodity->user_price)->getAmount();

            if ($commodity->draft_premium > 0) {
                $commodity->draft_premium = (new Decimal($commodity->draft_premium))->mul($userCommodity->premium / 100)->add($commodity->draft_premium)->getAmount();
            }

            if (is_array($config['category'])) {
                foreach ($config['category'] as &$price) {
                    $price = (new Decimal($price))->mul($userCommodity->premium / 100)->add($price)->getAmount();
                }
            }


            if (is_array($config['wholesale'])) {
                foreach ($config['wholesale'] as &$price) {
                    $price = (new Decimal($price))->mul($userCommodity->premium / 100)->add($price)->getAmount();
                }
            }

            if (is_array($config['category_wholesale'])) {
                foreach ($config['category_wholesale'] as &$arr) {
                    foreach ($arr as &$price) {
                        $price = (new Decimal($price))->mul($userCommodity->premium / 100)->add($price)->getAmount();
                    }
                }
            }

            if (is_array($config['sku'])) {
                foreach ($config['sku'] as &$arr) {
                    foreach ($arr as &$price) {
                        $price = (new Decimal($price))->mul($userCommodity->premium / 100)->add($price)->getAmount();
                    }
                }
            }
        }

        $commodity->config = $config;
    }

    /**
     * @param Commodity|int $commodity
     * @param int|string|float $amount
     * @return string
     * @throws JSONException
     */
    public function getSubstationPrice(Commodity|int $commodity, int|string|float $amount): string
    {
        if (is_int($commodity)) {
            $commodity = Commodity::query()->find($commodity);
        }

        if (!$commodity) {
            throw new JSONException("商品不存在");
        }

        $business = Business::get();

        if (!$business) {
            return (string)$amount;
        }

        /**
         * @var UserCommodity $userCommodity
         */
        $userCommodity = UserCommodity::query()->where("user_id", $business->user_id)->where("commodity_id", $commodity->id)->first();

        if (!$userCommodity) {
            return (string)$amount;
        }

        if ($userCommodity->premium > 0) {
            return (new Decimal($amount))->mul($userCommodity->premium / 100)->add($amount)->getAmount();
        }

        return (string)$amount;
    }

}
