<?php
declare(strict_types=1);

namespace App\Plugin\FansGurus\Service;

use App\Model\Category;
use App\Model\Commodity;
use App\Util\Plugin;
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
        $stats = ['categories_created' => 0, 'categories_updated' => 0, 'products_created' => 0, 'products_updated' => 0, 'products_price_updated' => 0, 'products_skipped' => 0];

        $categories = [];
        Db::connection()->transaction(function () use ($services, $config, $action, &$mapping, &$stats, &$categories): void {
            foreach ($services as $service) {
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

                $upstreamCategory = $this->categoryName($service);
                $categoryKey = trim($upstreamCategory) !== '' ? trim($upstreamCategory) : '其他服务';
                if (!isset($categories[$categoryKey])) {
                    $categories[$categoryKey] = $this->syncCategory($upstreamCategory, $config, $stats);
                }
                $category = $categories[$categoryKey];

                if ($action === 1) {
                    continue;
                }

                $commodity = $this->syncCommodity($category, $service, $config, $action, $stats);
                if (!$commodity) {
                    continue;
                }

                $mapping[(string)$commodity->id] = $this->mappingValue($serviceId, $config);
                $mapping[(string)$commodity->code] = $this->mappingValue($serviceId, $config);
            }
        });

        $config['mapping'] = json_encode($mapping, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        $config['last_sync_at'] = date('Y-m-d H:i:s');
        $config['last_sync_result'] = sprintf(
            '%s：分类新增%d/更新%d，商品新增%d/更新%d，价格更新%d，跳过%d',
            $this->actionName($action),
            $stats['categories_created'],
            $stats['categories_updated'],
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
        $prefix = (string)($config['sync_category_prefix'] ?? '');
        $name = trim($prefix . trim($upstreamCategory));
        if ($name === trim($prefix)) {
            $name = trim($prefix . '默认分类');
        }

        /** @var Category|null $category */
        $category = Category::query()->where('owner', 0)->where('name', $name)->first();
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

        $category->icon = $this->categoryIcon($name);
        $category->status = (int)($config['default_category_status'] ?? 1);
        $category->save();

        $stats[$created ? 'categories_created' : 'categories_updated']++;
        return $category;
    }

    private function categoryName(array $service): string
    {
        $text = mb_strtolower(
            html_entity_decode(
                trim((string)($service['category'] ?? '') . ' ' . (string)($service['name'] ?? '')),
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            ),
            'UTF-8'
        );

        $matches = [
            'Instagram' => ['instagram', 'ig ', 'ig-', 'ins ', 'ins-', 'threads'],
            'TikTok' => ['tiktok', 'tik tok', '抖音'],
            'X/Twitter' => ['twitter', 'tweet', 'retweet', 'x ', 'x-', '𝕏'],
            'YouTube' => ['youtube', 'youtu.be', 'yt ', 'yt-', '视频/短片', '视频喜欢', '观看次数'],
            'Telegram' => ['telegram', '电报'],
            'Facebook' => ['facebook', 'fb ', 'fb-'],
            'VK' => ['vk ', 'vk-', 'vkontakte'],
            'Spotify' => ['spotify'],
            'Discord' => ['discord'],
            'Twitch' => ['twitch'],
            'Reddit' => ['reddit'],
            'LinkedIn' => ['linkedin'],
            'Crypto' => ['bitcoin', 'crypto', 'binance', '币安', 'btc', '加密'],
            'GitHub' => ['github'],
            'Quora' => ['quora'],
            'WhatsApp' => ['whatsapp'],
            'LINE' => ['line '],
            'Google/Maps' => ['google', 'maps', '谷歌', '地图'],
            'Trustpilot' => ['trustpilot'],
            'Website Traffic' => ['website', 'traffic', 'site ', '网站', '流量', '访问量'],
            'Apple/App Store' => ['apple', 'app store', 'ios', 'iphone'],
            'Android/Google Play' => ['android', 'google play'],
            'SoundCloud' => ['soundcloud'],
            'Pinterest' => ['pinterest'],
            'Snapchat' => ['snapchat'],
        ];

        foreach ($matches as $category => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($text, mb_strtolower($needle, 'UTF-8'))) {
                    return $category;
                }
            }
        }

        return '其他服务';
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

    private function syncCommodity(Category $category, array $service, array $config, int $action, array &$stats): ?Commodity
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

        $cost = $this->costPerUnit((float)($service['rate'] ?? 0));
        $price = $this->price($cost, $config);

        if ($action === 4) {
            if ($created) {
                $stats['products_skipped']++;
                return null;
            }
            $commodity->factory_price = $cost;
            $commodity->price = $price;
            $commodity->user_price = $price;
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
        $commodity->delivery_message = 'FansGurus 上游订单处理中，请稍后查看订单状态。';
        $commodity->contact_type = 0;
        $commodity->password_status = 0;
        $commodity->sort = 0;
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
        $commodity->config = '';
        $commodity->save();

        $stats[$created ? 'products_created' : 'products_updated']++;
        return $commodity;
    }

    private function mappingValue(string $serviceId, array $config): array
    {
        return [
            'service' => is_numeric($serviceId) ? (int)$serviceId : $serviceId,
            'link_source' => (string)($config['default_link_source'] ?? 'contact'),
            'quantity' => 'order_num',
            'stock' => (string)($config['stock_value'] ?? '999999'),
        ];
    }

    private function costPerUnit(float $rate): float
    {
        return round(max(0, $rate) / 1000, 2);
    }

    private function price(float $cost, array $config): float
    {
        $multiplier = max(0, (float)($config['price_multiplier'] ?? 1.2));
        $plus = max(0, (float)($config['price_plus'] ?? 0));
        $floor = max(0, (float)($config['price_floor'] ?? 0.01));
        return round(max($floor, ($cost * $multiplier) + $plus), 2);
    }

    private function description(array $service): string
    {
        $rows = [
            '<p>FansGurus 上游自动同步商品。</p>',
            '<p>上游服务ID：' . htmlspecialchars((string)($service['service'] ?? ''), ENT_QUOTES, 'UTF-8') . '</p>',
            '<p>上游分类：' . htmlspecialchars((string)($service['category'] ?? ''), ENT_QUOTES, 'UTF-8') . '</p>',
            '<p>类型：' . htmlspecialchars((string)($service['type'] ?? ''), ENT_QUOTES, 'UTF-8') . '</p>',
            '<p>上游价格：' . htmlspecialchars((string)($service['rate'] ?? '0'), ENT_QUOTES, 'UTF-8') . ' / 1000</p>',
            '<p>购买范围：' . (int)($service['min'] ?? 0) . ' - ' . (int)($service['max'] ?? 0) . '</p>',
        ];

        return implode('', $rows);
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
