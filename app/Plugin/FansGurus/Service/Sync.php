<?php
declare(strict_types=1);

namespace App\Plugin\FansGurus\Service;

use App\Model\Category;
use App\Model\Commodity;
use App\Model\Coupon;
use App\Model\UserCategory;
use App\Plugin\Support\ExchangeRate;
use App\Plugin\Support\UpstreamOrderMessage;
use App\Util\Plugin;
use App\Util\Ini;
use Illuminate\Database\Capsule\Manager as Db;
use Kernel\Exception\JSONException;

class Sync
{
    private const PLUGIN = 'FansGurus';

    /**
     * @throws JSONException
     */
    public function run(array $config): array
    {
        $action = (int)($config['sync_action'] ?? 0);
        if ($action <= 0) {
            return ['last_sync_result' => '未执行同步'];
        }
        if ($action !== 1) {
            $config = ExchangeRate::applyUsdToCny($config, self::PLUGIN);
        }

        $client = new Client(
            $this->normalizeApiUrl((string)($config['api_url'] ?? 'https://fansgurus.com/api/v2')),
            trim((string)($config['api_key'] ?? '')),
            (int)($config['timeout'] ?? 30)
        );

        $services = $client->services();
        if (!$this->isList($services)) {
            throw new JSONException('FansGurus 服务列表返回格式异常');
        }

        $limit = max(0, (int)($config['sync_limit'] ?? 0));
        if ($limit > 0) {
            $services = array_slice($services, 0, $limit);
        }

        $mapping = $this->parseMapping((string)($config['mapping'] ?? ''));
        $stats = ['categories_created' => 0, 'categories_updated' => 0, 'categories_deleted' => 0, 'products_created' => 0, 'products_updated' => 0, 'products_price_updated' => 0, 'products_skipped' => 0];

        $categories = [];
        Db::connection()->transaction(function () use ($services, $config, $action, &$mapping, &$stats, &$categories): void {
            foreach ($services as $index => $service) {
                if (!is_array($service)) {
                    $stats['products_skipped']++;
                    continue;
                }

                $serviceId = trim((string)($service['service'] ?? ''));
                $name = trim((string)($service['name'] ?? ''));
                if ($serviceId === '' || $name === '') {
                    $stats['products_skipped']++;
                    continue;
                }

                $path = $this->categoryPath($service);
                $categoryKey = implode('>', $path);
                if (!isset($categories[$categoryKey])) {
                    $categories[$categoryKey] = $this->syncCategoryPath($path, $config, $stats);
                }
                $category = $categories[$categoryKey];

                if ($action === 1) {
                    continue;
                }

                $commodity = $this->syncCommodity($category, $service, $config, $action, $stats, $index + 1);
                if (!$commodity) {
                    continue;
                }

                $mapping[(string)$commodity->id] = $this->mappingValue($serviceId, $config);
                $mapping[(string)$commodity->code] = $this->mappingValue($serviceId, $config);
            }
        });

        if (in_array($action, [2, 3], true)) {
            $this->reconcilePluginCategories($config, $stats);
        }

        $config['mapping'] = json_encode($mapping, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        $config['last_sync_at'] = date('Y-m-d H:i:s');
        $config['last_sync_result'] = sprintf(
            '%s：分类新增%d/更新%d/清理%d，商品新增%d/更新%d，价格更新%d，跳过%d',
            $this->actionName($action),
            $stats['categories_created'],
            $stats['categories_updated'],
            $stats['categories_deleted'],
            $stats['products_created'],
            $stats['products_updated'],
            $stats['products_price_updated'],
            $stats['products_skipped']
        );
        $config['sync_action'] = '0';

        setConfig($config, BASE_PATH . '/app/Plugin/' . self::PLUGIN . '/Config/Config.php');
        Plugin::log(self::PLUGIN, '同步完成：' . $config['last_sync_result']);

        return $stats + [
                'mapping' => $config['mapping'],
                'last_sync_at' => $config['last_sync_at'],
                'last_sync_result' => $config['last_sync_result'],
            ];
    }

    private function syncCategory(string $upstreamCategory, array $config, array &$stats): Category
    {
        return $this->syncCategoryPath([$upstreamCategory], $config, $stats);
    }

    private function syncCategoryPath(array $path, array $config, array &$stats): Category
    {
        $parent = null;
        $category = null;
        foreach ($path as $index => $name) {
            $sort = $index === 0 ? $this->categorySort((string)$name) : ($index + 1) * 10;
            $category = $this->syncCategoryNode((string)$name, $config, $stats, $parent, $sort);
            $parent = $category;
        }

        return $category ?? $this->syncCategoryNode('默认分类', $config, $stats, null, 9999);
    }

    private function syncCategoryNode(string $upstreamCategory, array $config, array &$stats, ?Category $parent = null, int $sort = 0): Category
    {
        $prefix = (string)($config['sync_category_prefix'] ?? '');
        $name = trim($prefix . trim($upstreamCategory));
        if ($name === trim($prefix)) {
            $name = trim($prefix . '默认分类');
        }

        /** @var Category|null $category */
        $query = Category::query()->where('owner', 0)->where('name', $name);
        if ($parent) {
            $query->where('pid', $parent->id);
        } else {
            $query->where(function ($builder): void {
                $builder->whereNull('pid')->orWhere('pid', 0);
            });
        }
        $category = $query->first();
        $created = false;
        if (!$category) {
            $category = new Category();
            $category->name = $name;
            $category->owner = 0;
            $category->create_time = date('Y-m-d H:i:s');
            $category->hide = 0;
            $category->sort = 0;
            $category->icon = '/favicon.ico';
            $created = true;
        }

        $category->name = $name;
        $category->pid = $parent?->id;
        $category->icon = $this->categoryIcon($parent ? (string)$parent->name : $name);
        $category->sort = $sort ?: $this->categorySort($name);
        $category->status = (int)($config['default_category_status'] ?? 1);
        $category->hide = 0;
        $category->save();

        $stats[$created ? 'categories_created' : 'categories_updated']++;
        return $category;
    }

    private function categoryName(array $service): string
    {
        $categoryText = $this->searchableText((string)($service['category'] ?? ''));
        $nameText = $this->searchableText((string)($service['name'] ?? ''));

        $matches = [
            'Instagram' => ['instagram', 'ig ', 'ig-', 'ins ', 'ins-', 'threads'],
            'TikTok' => ['tiktok', 'tik tok', '抖音'],
            'X/Twitter' => ['twitter', 'tweet', 'retweet', 'x.com', 'x/twitter', '𝕏'],
            'YouTube' => ['youtube', 'youtu.be', 'yt ', 'yt-'],
            'Telegram' => ['telegram', '电报'],
            'Facebook' => ['facebook', 'fb ', 'fb-'],
            'VK' => ['vk ', 'vk-', 'vk.com', 'vkontakte'],
            'Spotify' => ['spotify'],
            'Discord' => ['discord'],
            'Twitch' => ['twitch'],
            'Reddit' => ['reddit'],
            'LinkedIn' => ['linkedin'],
            'Crypto' => ['bitcoin', 'crypto', 'btc', '加密'],
            'Binance' => ['binance', '币安', 'bnb'],
            'GitHub' => ['github'],
            'Quora' => ['quora'],
            'WhatsApp' => ['whatsapp'],
            'Apple/App Store' => ['apple', 'app store', 'ios', 'iphone'],
            'Android/Google Play' => ['android', 'google play'],
            'LINE' => ['line '],
            'Google/Maps' => ['google', 'maps', '谷歌', '地图'],
            'Trustpilot' => ['trustpilot'],
            'Website Traffic' => ['website', 'traffic', 'site ', '网站', '流量', '访问量'],
        ];

        foreach ($matches as $category => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($categoryText, mb_strtolower($needle, 'UTF-8'))) {
                    return $category;
                }
            }
        }

        foreach ($matches as $category => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($nameText, mb_strtolower($needle, 'UTF-8'))) {
                    return $category;
                }
            }
        }

        return '其他服务';
    }

    private function searchableText(string $value): string
    {
        return mb_strtolower(html_entity_decode(trim($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'), 'UTF-8');
    }

    private function categoryPath(array $service): array
    {
        $parent = $this->categoryName($service);
        $raw = $this->cleanCategoryLabel((string)($service['category'] ?? ''));
        $child = $this->subcategoryName($raw, $parent);
        if ($child !== '') {
            return [$parent, $child];
        }

        return [$parent, '综合服务'];
    }

    private function subcategoryName(string $raw, string $parent): string
    {
        if ($raw === '') {
            return '';
        }

        $parts = $this->splitCategoryPath($raw);
        if (count($parts) >= 2 && $this->sameCategoryLabel($parts[0], $parent)) {
            return $parts[1];
        }

        if (count($parts) >= 2) {
            return implode('-', $parts);
        }

        return $this->sameCategoryLabel($raw, $parent) ? '' : $raw;
    }

    private function splitCategoryPath(string $category): array
    {
        $category = trim($category);
        if ($category === '') {
            return [];
        }

        $parts = preg_split('/\s*(?:[-_\/|>＞:：]+)\s*/u', $category) ?: [];
        $parts = array_values(array_filter(array_map(fn(string $part): string => $this->cleanCategoryLabel($part), $parts), static fn(string $part): bool => $part !== ''));
        if (count($parts) <= 1) {
            return $parts;
        }

        return [$parts[0], implode('-', array_slice($parts, 1))];
    }

    private function sameCategoryLabel(string $left, string $right): bool
    {
        return mb_strtolower($this->cleanCategoryLabel($left), 'UTF-8') === mb_strtolower($this->cleanCategoryLabel($right), 'UTF-8');
    }

    private function cleanCategoryLabel(string $value): string
    {
        return trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function categoryIcon(string $category): string
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
            'SoundCloud' => 'soundcloud',
            'Pinterest' => 'pinterest',
            'Snapchat' => 'snapchat',
            '其他服务' => 'other',
        ];

        $file = $icons[$category] ?? 'other';
        return '/app/Plugin/' . self::PLUGIN . '/Assets/icons/' . $file . '.svg';
    }

    private function categorySort(string $category): int
    {
        $order = $this->platformCategoryNames();

        $position = array_search($category, $order, true);
        return $position === false ? 9999 : ($position + 1) * 10;
    }

    private function syncCommodity(Category $category, array $service, array $config, int $action, array &$stats, int $sort): ?Commodity
    {
        $serviceId = trim((string)$service['service']);
        $code = 'FG_' . preg_replace('/[^A-Za-z0-9_]/', '_', $serviceId);

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

        $cost = $this->costPerUnit((float)($service['rate'] ?? 0), $config);
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
        $commodity->name = mb_substr(trim((string)$service['name']), 0, 255);
        $commodity->description = $this->description($service);
        $commodity->cover = $category->icon ?: (string)($config['default_cover'] ?? '/favicon.ico');
        $commodity->status = (int)($config['default_product_status'] ?? 1);
        $commodity->api_status = 1;
        $commodity->delivery_way = 1;
        $commodity->delivery_auto_mode = 0;
        $commodity->delivery_message = UpstreamOrderMessage::PROCESSING;
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
        $commodity->recommend = 0;
        $commodity->send_email = 0;
        $commodity->only_user = 0;
        $commodity->purchase_count = 0;
        $commodity->widget = '';
        $commodity->level_price = null;
        $commodity->level_disable = 0;
        $commodity->minimum = max(0, (int)($service['min'] ?? 0));
        $commodity->maximum = max(0, (int)($service['max'] ?? 0));
        $commodity->shared_sync = 0;
        $commodity->inventory_sync = 0;
        $commodity->shared_amount_sync = 0;
        $commodity->shared_config_sync = 0;
        $commodity->hide = 0;
        $commodity->stock = (int)($config['stock_value'] ?? 999999);
        $commodity->config = $this->serviceConfig($service);
        $commodity->save();

        $stats[$created ? 'products_created' : 'products_updated']++;
        return $commodity;
    }

    public function reconcilePluginCategories(array $config = [], ?array &$stats = null): void
    {
        if ($stats === null) {
            $stats = ['categories_deleted' => 0];
        }
        $stats['categories_deleted'] ??= 0;

        $validCategoryIds = Commodity::query()
            ->where('code', 'like', 'FG_%')
            ->whereNotNull('category_id')
            ->distinct()
            ->pluck('category_id')
            ->filter()
            ->map(static fn($id): int => (int)$id)
            ->all();

        $validCategoryIds = $this->withParentCategoryIds($validCategoryIds);
        $platformNames = $this->prefixedPlatformCategoryNames($config);
        $validCategoryIds = $validCategoryIds ?: [0];

        do {
            $deletedThisPass = 0;
            $categories = Category::query()
                ->where('owner', 0)
                ->where('icon', 'like', '%/app/Plugin/' . self::PLUGIN . '/Assets/icons/%')
                ->whereNotIn('name', $platformNames)
                ->whereNotIn('id', $validCategoryIds)
                ->orderBy('id', 'desc')
                ->get();

            foreach ($categories as $category) {
                $id = (int)$category->id;
                if (Commodity::query()->where('category_id', $id)->where('code', 'not like', 'FG_%')->exists()) {
                    continue;
                }

                $hasChildren = Category::query()->where('pid', $id)->exists();
                $hasProducts = Commodity::query()->where('category_id', $id)->exists();
                if ($hasChildren || $hasProducts) {
                    continue;
                }

                Coupon::query()->where('category_id', $id)->update(['category_id' => 0]);
                UserCategory::query()->where('category_id', $id)->delete();
                $category->delete();
                $stats['categories_deleted']++;
                $deletedThisPass++;
            }
        } while ($deletedThisPass > 0);
    }

    private function withParentCategoryIds(array $categoryIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $categoryIds))));
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

        return $ids;
    }

    private function platformCategoryNames(): array
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

    private function prefixedPlatformCategoryNames(array $config): array
    {
        $prefix = (string)($config['sync_category_prefix'] ?? '');
        return array_map(static fn(string $name): string => trim($prefix . $name), $this->platformCategoryNames());
    }

    private function mappingValue(string $serviceId, array $config): array
    {
        $linkSource = trim((string)($config['default_link_source'] ?? ''));
        // 同步商品统一使用独立目标链接。旧配置仍可能是 contact；下单时也会
        // 优先读取 target_link，因此升级后不需要手动改每一条映射。
        if ($linkSource === '' || $linkSource === 'contact') {
            $linkSource = 'widget:target_link';
        }

        return [
            'service' => is_numeric($serviceId) ? (int)$serviceId : $serviceId,
            'link_source' => $linkSource,
            'quantity' => 'order_num',
            'stock' => (string)($config['stock_value'] ?? '999999'),
        ];
    }

    private function costPerUnit(float $rate, array $config): float
    {
        $usdToCny = ExchangeRate::rate($config);
        return round((max(0, $rate) * $usdToCny) / 1000, 4);
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

    private function description(array $service): string
    {
        $rate = htmlspecialchars($this->formatRate((string)($service['rate'] ?? '0')), ENT_QUOTES, 'UTF-8');
        $min = (int)($service['min'] ?? 0);
        $max = (int)($service['max'] ?? 0);
        $name = htmlspecialchars($this->displayName((string)($service['name'] ?? '')), ENT_QUOTES, 'UTF-8');
        $category = htmlspecialchars((string)($service['category'] ?? ''), ENT_QUOTES, 'UTF-8');
        $type = htmlspecialchars((string)($service['type'] ?? ''), ENT_QUOTES, 'UTF-8');
        $rows = [
            '<table style="width:100%;border-collapse:collapse;margin:10px 0;">',
            '<tr><td style="border:1px solid #eee;padding:8px;">商品名称</td><td style="border:1px solid #eee;padding:8px;">' . $name . '</td></tr>',
            '<tr><td style="border:1px solid #eee;padding:8px;">商品分类</td><td style="border:1px solid #eee;padding:8px;">' . $category . '</td></tr>',
            '<tr><td style="border:1px solid #eee;padding:8px;">商品类型</td><td style="border:1px solid #eee;padding:8px;">' . $type . '</td></tr>',
            '<tr><td style="border:1px solid #eee;padding:8px;">每 1000 人的费用</td><td style="border:1px solid #eee;padding:8px;">' . $rate . '</td></tr>',
            '<tr><td style="border:1px solid #eee;padding:8px;">最低订购</td><td style="border:1px solid #eee;padding:8px;">' . $min . '</td></tr>',
            '<tr><td style="border:1px solid #eee;padding:8px;">最高订购</td><td style="border:1px solid #eee;padding:8px;">' . $max . '</td></tr>',
            '<tr><td style="border:1px solid #eee;padding:8px;">支持滴灌</td><td style="border:1px solid #eee;padding:8px;">' . $this->boolText($service['dripfeed'] ?? false) . '</td></tr>',
            '<tr><td style="border:1px solid #eee;padding:8px;">支持补单</td><td style="border:1px solid #eee;padding:8px;">' . $this->boolText($service['refill'] ?? false) . '</td></tr>',
            '<tr><td style="border:1px solid #eee;padding:8px;">支持取消</td><td style="border:1px solid #eee;padding:8px;">' . $this->boolText($service['cancel'] ?? false) . '</td></tr>',
            '</table>',
        ];

        return implode('', $rows);
    }

    private function displayName(string $name): string
    {
        return trim((string)preg_replace('/^FreshCoins\s*/iu', '', $name));
    }

    private function serviceConfig(array $service): string
    {
        return Ini::toConfig([
            'fansgurus_specs' => [
                'service_id' => (string)($service['service'] ?? ''),
                'rate_per_1000' => $this->formatRate((string)($service['rate'] ?? '0'), false),
                'minimum_order' => (string)max(0, (int)($service['min'] ?? 0)),
                'maximum_order' => (string)max(0, (int)($service['max'] ?? 0)),
                'type' => $this->configValue((string)($service['type'] ?? '')),
                'category' => $this->configValue((string)($service['category'] ?? '')),
                'dripfeed' => $this->boolValue($service['dripfeed'] ?? false),
                'refill' => $this->boolValue($service['refill'] ?? false),
                'cancel' => $this->boolValue($service['cancel'] ?? false),
            ],
        ]);
    }

    private function boolText(mixed $value): string
    {
        return $this->boolValue($value) === '1' ? '支持' : '不支持';
    }

    private function boolValue(mixed $value): string
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
    }

    private function configValue(string $value): string
    {
        return str_replace(["\r", "\n", '='], [' ', ' ', '-'], trim($value));
    }

    private function formatRate(string $rate, bool $withCurrency = true): string
    {
        $value = trim($rate);
        if ($value === '' || !is_numeric($value)) {
            $value = '0';
        }

        $formatted = rtrim(rtrim(number_format((float)$value, 6, '.', ''), '0'), '.');
        return $withCurrency ? '$' . $formatted . ' / 1000' : $formatted;
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
            return 'https://fansgurus.com/api/v2';
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
