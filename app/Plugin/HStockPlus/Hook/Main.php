<?php
declare(strict_types=1);

namespace App\Plugin\HStockPlus\Hook;

use App\Consts\Hook;
use App\Model\Category;
use App\Model\Commodity;
use App\Model\Order;
use App\Model\Pay;
use App\Plugin\HStockPlus\Service\Client;
use App\Plugin\HStockPlus\Service\Sync;
use App\Plugin\Support\UpstreamCategoryStatus;
use App\Plugin\Support\UpstreamPriceRecalculator;
use App\Util\Ini;
use App\Util\Plugin;
use Kernel\Annotation\Hook as HookPoint;
use Kernel\Annotation\Plugin as PluginState;
use Kernel\Exception\JSONException;
use Kernel\Plugin\Entity\Stock;

class Main
{
    private const PLUGIN = 'HStockPlus';

    #[PluginState(state: PluginState::START)]
    public function start(): void
    {
        $config = $this->config();
        $sync = new Sync();
        $sync->cleanupLegacyCategories();
        $sync->normalizeAccountSubcategories($config);
        $sync->reconcilePluginCategories($config);
        $syncAction = (int)($config['sync_action'] ?? 0);
        if ($syncAction <= 0 && (int)($config['auto_sync_on_start'] ?? 0) === 1) {
            $syncAction = 3;
            $config['sync_action'] = '3';
            $config['sync_source'] = $config['sync_source'] ?? 'products';
            $config['sync_limit'] = '0';
        }
        if ($syncAction > 0) {
            try {
                $sync->run($config);
                $this->setSyncedProductsStatus(
                    (int)($config['default_product_status'] ?? 1),
                    (int)($config['default_category_status'] ?? 1),
                    $config
                );
                $this->log("插件已启动，已执行同步动作{$syncAction}");
                $config = $this->config();
            } catch (\Throwable $e) {
                $this->log('启用同步失败：' . $e->getMessage());
                throw $e;
            }
        }

        if ((int)($config['enable_products_on_start'] ?? 1) === 1) {
            $categoryStatus = (int)($config['default_category_status'] ?? 1);
            $productStatus = (int)($config['default_product_status'] ?? 1);
            $changed = $this->setSyncedProductsStatus($productStatus, $categoryStatus, $config);
            $this->log("插件已启动，已恢复上架分类{$changed['categories']}个，商品{$changed['products']}个");
            return;
        }

        $this->log('插件已启动');
    }

    #[PluginState(state: PluginState::STOP)]
    public function stop(): void
    {
        $config = $this->config();
        if ((int)($config['disable_products_on_stop'] ?? 1) === 1) {
            $changed = $this->setSyncedProductsStatus(0, 0, $config);
            $this->log("插件已停止，已下架分类{$changed['categories']}个，商品{$changed['products']}个");
            return;
        }

        $this->log('插件已停止');
    }

    #[PluginState(state: PluginState::SAVE_CONFIG)]
    public function saveConfig(string $pluginName, array $map): void
    {
        if ($pluginName !== self::PLUGIN) {
            return;
        }

        if (isset($map['mapping'])) {
            $this->parseMapping((string)$map['mapping']);
        }

        $config = array_replace($this->config(), $map);
        $priceConfigChanged = $this->priceConfigChanged($map);
        if ((int)($config['auto_sync_on_save'] ?? 0) === 1) {
            if ((int)($config['sync_action'] ?? 0) <= 0) {
                $config['sync_action'] = '3';
            }
            try {
                (new Sync())->run($config);
                $this->setSyncedProductsStatus(
                    (int)($config['default_product_status'] ?? 1),
                    (int)($config['default_category_status'] ?? 1),
                    $config
                );
            } catch (\Throwable $e) {
                $this->log('同步失败：' . $e->getMessage());
                throw $e;
            }
        } elseif ($priceConfigChanged) {
            $this->recalculateSyncedPrices($config);
        }
    }

    private function priceConfigChanged(array $map): bool
    {
        foreach (['exchange_rate_auto', 'usd_to_cny', 'price_multiplier', 'price_plus', 'price_floor', 'smm_rate_per_1000'] as $key) {
            if (array_key_exists($key, $map)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @throws JSONException
     */
    private function recalculateSyncedPrices(array $config): void
    {
        $result = UpstreamPriceRecalculator::recalculate(self::PLUGIN, $config);
        $config = $result['config'];
        $config['last_sync_at'] = date('Y-m-d H:i:s');
        $config['last_sync_result'] = sprintf(
            '汇率/价格配置已实时重算：商品更新%d，跳过%d',
            (int)$result['updated'],
            (int)$result['skipped']
        );
        $config['sync_action'] = '0';

        setConfig($config, BASE_PATH . '/app/Plugin/' . self::PLUGIN . '/Config/Config.php');
        $this->log($config['last_sync_result']);
    }

    #[HookPoint(point: Hook::SERVICE_SHOP_GET_ITEM_STOCK)]
    public function stock(Commodity $commodity, ?string $race = null, ?array $sku = []): ?Stock
    {
        $config = $this->config();
        if ((int)($config['enabled_stock'] ?? 0) !== 1) {
            return null;
        }

        $mapping = $this->mappingFor($commodity);
        if (!$mapping) {
            return null;
        }

        if (isset($mapping['stock'])) {
            return new Stock((string)$mapping['stock']);
        }

        $service = $mapping['service'] ?? null;
        if (!$service) {
            return new Stock((string)($config['stock_value'] ?? '999999'));
        }

        try {
            $inventory = $this->client($config)->inventory($service);
            $stock = $inventory['stock'] ?? $inventory['available'] ?? $config['stock_value'] ?? '999999';
        } catch (\Throwable $e) {
            $this->log("库存查询失败 commodity={$commodity->id} service={$service} error={$e->getMessage()}");
            $stock = $config['stock_value'] ?? '999999';
        }

        return new Stock((string)$stock);
    }

    /**
     * @throws JSONException
     */
    #[HookPoint(point: Hook::USER_API_ORDER_PAY_AFTER)]
    public function payAfter(Commodity $commodity, Order $order, ?Pay $pay = null): void
    {
        $config = $this->config();
        if ((int)($config['enabled_trade'] ?? 0) !== 1) {
            return;
        }

        $mapping = $this->mappingFor($commodity);
        if (!$mapping) {
            return;
        }

        $service = $mapping['service'] ?? null;
        if (!$service) {
            throw new JSONException("HStockPlus 商品 {$commodity->id} 未配置 service");
        }

        $link = $this->resolveLink($mapping, $config, $order);
        $quantity = $this->resolveQuantity($mapping, $order);
        $extra = $this->resolveExtra($mapping, $order);

        $this->log("提交订单 trade_no={$order->trade_no} commodity={$commodity->id} service={$service} quantity={$quantity} link={$link}");
        $result = $this->client($config)->add($service, $link, $quantity, $extra);

        if (!isset($result['order'])) {
            throw new JSONException('HStockPlus 下单成功但未返回上游订单号');
        }

        $upstreamOrder = (string)$result['order'];
        $order->secret = $this->formatSecret($upstreamOrder, (string)$service, $quantity, $link, $result);
        $order->delivery_status = 1;
        $order->save();

        Plugin::setCache(self::PLUGIN, 'orders', $order->trade_no, [
            'trade_no' => $order->trade_no,
            'upstream_order' => $upstreamOrder,
            'service' => (string)$service,
            'quantity' => $quantity,
            'link' => $link,
            'entity_type' => (string)($mapping['entity_type'] ?? $mapping['entityType'] ?? ''),
            'response' => $result,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->log("下单成功 trade_no={$order->trade_no} upstream_order={$upstreamOrder}");
    }

    #[HookPoint(point: Hook::USER_API_INDEX_QUERY_SECRET)]
    public function querySecret(Order $order): void
    {
        $config = $this->config();
        if ((int)($config['enabled_query_sync'] ?? 0) !== 1) {
            return;
        }

        $cache = Plugin::getCache(self::PLUGIN, 'orders', $order->trade_no);
        if (!is_array($cache) || empty($cache['upstream_order'])) {
            return;
        }

        try {
            $status = $this->client($config)->status($cache['upstream_order']);
        } catch (\Throwable $e) {
            $this->log("查询订单失败 trade_no={$order->trade_no} upstream_order={$cache['upstream_order']} error={$e->getMessage()}");
            return;
        }

        $secret = $this->formatStatusSecret((string)$cache['upstream_order'], $status, $cache);
        if ($secret !== '' && $secret !== (string)$order->secret) {
            $order->secret = $secret;
            $order->delivery_status = 1;
            $order->save();
            Plugin::setCache(self::PLUGIN, 'orders', $order->trade_no, $cache + [
                    'last_status' => $status,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
        }
    }

    #[HookPoint(point: Hook::KERNEL_INIT)]
    public function ensurePublicRoute(): void
    {
        $file = BASE_PATH . '/.htaccess';
        $rule = ' RewriteRule ^2$ index.php?s=/plugin/HStockPlus/index/index [QSA,L]';
        if (!is_file($file) || !is_writable($file)) {
            return;
        }

        $content = (string)file_get_contents($file);
        if (str_contains($content, 'index.php?s=/plugin/HStockPlus/index/index')) {
            return;
        }

        if (str_contains($content, 'RewriteBase /')) {
            $content = preg_replace('/(RewriteBase \\/\\R)/', '$1' . $rule . PHP_EOL, $content, 1) ?? $content;
        } else {
            $content .= PHP_EOL . '<IfModule mod_rewrite.c>' . PHP_EOL
                . ' RewriteEngine on' . PHP_EOL
                . ' RewriteBase /' . PHP_EOL
                . $rule . PHP_EOL
                . '</IfModule>' . PHP_EOL;
        }

        file_put_contents($file, $content);
    }

    #[HookPoint(point: Hook::USER_API_INDEX_CATEGORY_LIST)]
    public function categoryList(array &$category): void
    {
        $this->cleanCategoryList($category);
    }

    private function config(): array
    {
        return Plugin::getConfig(self::PLUGIN, false);
    }

    private function setSyncedProductsStatus(int $productStatus, int $categoryStatus, array $config): array
    {
        $products = Commodity::query()->where('code', 'like', 'HSP\_%');
        $categoryIds = (clone $products)
            ->whereNotNull('category_id')
            ->pluck('category_id')
            ->unique()
            ->values()
            ->all();
        $categoryIds = $this->withParentCategoryIds($categoryIds);
        $enabledCategoryIds = $this->enabledCategoryIds($categoryIds, $config);
        $enabledCategoryLookup = array_fill_keys($enabledCategoryIds, true);

        $productCount = (clone $products)->count();

        if ($productStatus === 1) {
            if ($enabledCategoryIds) {
                (clone $products)->whereIn('category_id', $enabledCategoryIds)->update(['status' => 1]);
            }
            (clone $products)->where(function ($query) use ($enabledCategoryIds): void {
                if ($enabledCategoryIds) {
                    $query->whereNotIn('category_id', $enabledCategoryIds)->orWhereNull('category_id');
                    return;
                }
                $query->whereRaw('1=1');
            })->update(['status' => 0]);
        } else {
            $products->update(['status' => 0]);
        }

        // Categories are shared by upstream plugins. Keep every category and
        // ancestor needed by any product that remains active after this update.
        $activeCategoryTree = UpstreamCategoryStatus::activeCategoryTreeIds();
        $categoryCount = 0;
        foreach ($categoryIds as $categoryId) {
            $categoryId = (int)$categoryId;

            if ($categoryStatus === 1 && isset($enabledCategoryLookup[$categoryId])) {
                $categoryCount += Category::query()
                    ->where('owner', 0)
                    ->where('id', $categoryId)
                    ->update(['status' => 1]);
                continue;
            }

            if (isset($activeCategoryTree[$categoryId])) {
                continue;
            }

            $categoryCount += Category::query()
                ->where('owner', 0)
                ->where('id', $categoryId)
                ->update(['status' => 0]);
        }

        return [
            'products' => (int)$productCount,
            'categories' => (int)$categoryCount,
        ];
    }

    private function enabledCategoryIds(array $categoryIds, array $config): array
    {
        $enabledTop = array_flip($this->enabledTopCategories($config));
        $ids = array_values(array_unique(array_filter(array_map('intval', $categoryIds))));
        if (!$ids) {
            return [];
        }

        $rows = Category::query()->whereIn('id', $ids)->get(['id', 'pid', 'name']);
        $byId = [];
        foreach ($rows as $row) {
            $byId[(int)$row->id] = [
                'id' => (int)$row->id,
                'pid' => (int)$row->pid,
                'name' => (string)$row->name,
            ];
        }

        return array_values(array_filter($ids, function (int $id) use ($enabledTop, $byId): bool {
            $current = $byId[$id] ?? null;
            while ($current && (int)$current['pid'] > 0 && isset($byId[(int)$current['pid']])) {
                $current = $byId[(int)$current['pid']];
            }

            return $current && isset($enabledTop[$this->cleanCategoryName((string)$current['name'])]);
        }));
    }

    private function enabledTopCategories(array $config): array
    {
        $raw = (string)($config['enabled_top_categories'] ?? '账户');
        $items = preg_split('/[,，|\\s]+/u', $raw) ?: [];
        $enabled = array_values(array_filter(array_map([$this, 'cleanCategoryName'], $items), static fn(string $name): bool => $name !== ''));

        return $enabled ?: ['账户'];
    }

    private function cleanCategoryName(string $name): string
    {
        $name = trim(html_entity_decode(strip_tags($name), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $prefixes = ['HStockPlus-', 'HStockPlus - ', 'HStockPlus'];
        foreach ($prefixes as $prefix) {
            if (str_starts_with($name, $prefix)) {
                $name = trim(substr($name, strlen($prefix)));
            }
        }

        return $name;
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

    private function client(array $config): Client
    {
        return new Client(
            $this->normalizeApiUrl((string)($config['api_url'] ?? 'https://hstockplus.com/api/v2')),
            trim((string)($config['api_key'] ?? '')),
            (int)($config['timeout'] ?? 30)
        );
    }

    /**
     * @throws JSONException
     */
    private function parseMapping(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        $mapping = json_decode($raw, true);
        if (!is_array($mapping)) {
            throw new JSONException('HStockPlus 商品映射 JSON 格式不正确');
        }

        return $mapping;
    }

    private function mappingFor(Commodity $commodity): ?array
    {
        $mapping = [];
        try {
            $mapping = $this->parseMapping((string)($this->config()['mapping'] ?? ''));
        } catch (\Throwable) {
        }

        $keys = [(string)$commodity->id, (string)$commodity->code];
        foreach ($keys as $key) {
            if (isset($mapping[$key]) && is_array($mapping[$key])) {
                return $mapping[$key];
            }
        }

        $autoMapping = $this->mappingFromCommodity($commodity);
        if ($autoMapping) {
            return $autoMapping;
        }

        return null;
    }

    private function mappingFromCommodity(Commodity $commodity): ?array
    {
        if (!is_string($commodity->code) || !str_starts_with($commodity->code, 'HSP_')) {
            return null;
        }

        $service = substr($commodity->code, 4);
        $entityType = '';
        $stock = '';
        try {
            $config = Ini::toArray((string)$commodity->config);
            $specs = (array)($config['hstockplus_specs'] ?? []);
            $service = trim((string)($specs['service'] ?? $service));
            $entityType = trim((string)($specs['entity_type'] ?? ''));
            $stock = trim((string)($specs['stock'] ?? ''));
        } catch (\Throwable) {
        }

        if ($service === '') {
            return null;
        }

        return [
            'service' => is_numeric($service) ? (int)$service : $service,
            'link_source' => (string)($this->config()['default_link_source'] ?? 'contact'),
            'quantity' => 'order_num',
            'stock' => $stock !== '' ? $stock : (string)($commodity->stock ?? ''),
            'entity_type' => $entityType,
            'auto_delivery' => '1',
        ];
    }

    /**
     * @throws JSONException
     */
    private function resolveLink(array $mapping, array $config, Order $order): string
    {
        $source = trim((string)($mapping['link_source'] ?? $config['default_link_source'] ?? 'contact'));

        if ($source === '' || $source === 'contact') {
            $link = (string)$order->contact;
        } elseif (str_starts_with($source, 'widget:')) {
            $name = substr($source, 7);
            $widget = (array)json_decode((string)$order->widget, true);
            $link = (string)($widget[$name]['value'] ?? '');
        } elseif (str_starts_with($source, 'fixed:')) {
            $link = substr($source, 6);
        } else {
            $link = $source;
        }

        $link = trim($link);
        $entityType = (string)($mapping['entity_type'] ?? $mapping['entityType'] ?? '');
        if ($link === '' && $entityType === 'product') {
            return '';
        }

        if ($link === '') {
            throw new JSONException("HStockPlus 订单 {$order->trade_no} 未获取到链接参数");
        }

        return $link;
    }

    /**
     * @throws JSONException
     */
    private function resolveQuantity(array $mapping, Order $order): int
    {
        $quantity = $mapping['quantity'] ?? 'order_num';

        if (is_numeric($quantity)) {
            $num = (int)$quantity;
        } else {
            $quantity = (string)$quantity;
            if ($quantity === '' || $quantity === 'order_num') {
                $num = (int)$order->card_num;
            } elseif (str_starts_with($quantity, 'fixed:')) {
                $num = (int)substr($quantity, 6);
            } elseif (str_starts_with($quantity, 'widget:')) {
                $name = substr($quantity, 7);
                $widget = (array)json_decode((string)$order->widget, true);
                $num = (int)($widget[$name]['value'] ?? 0);
            } else {
                $num = (int)$quantity;
            }
        }

        if ($num <= 0) {
            throw new JSONException("HStockPlus 订单 {$order->trade_no} 数量必须大于 0");
        }

        return $num;
    }

    private function resolveExtra(array $mapping, Order $order): array
    {
        $extra = [];
        foreach ((array)($mapping['extra'] ?? []) as $key => $source) {
            $value = $this->resolveOptionalSource((string)$source, $order);
            if ($value !== '') {
                $extra[(string)$key] = $value;
            }
        }
        return $extra;
    }

    private function resolveOptionalSource(string $source, Order $order): string
    {
        $source = trim($source);
        if ($source === 'contact') {
            return trim((string)$order->contact);
        }
        if (str_starts_with($source, 'widget:')) {
            $name = substr($source, 7);
            $widget = (array)json_decode((string)$order->widget, true);
            return trim((string)($widget[$name]['value'] ?? ''));
        }
        if (str_starts_with($source, 'fixed:')) {
            return trim(substr($source, 6));
        }
        return $source;
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

    private function cleanCategoryList(array &$category): void
    {
        foreach ($category as &$item) {
            if (is_array($item)) {
                if (isset($item['name']) && is_scalar($item['name'])) {
                    $item['name'] = $this->cleanTitle((string)$item['name']);
                }
                if (isset($item['children']) && is_array($item['children'])) {
                    $this->cleanCategoryList($item['children']);
                }
            }
        }
        unset($item);
    }

    private function cleanTitle(string $value): string
    {
        $value = trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $value = preg_replace('/^(?:HStockPlus|HStock\\s*Plus)\\s*[-_：:|]*\\s*/iu', '', $value) ?? $value;
        return trim($value);
    }

    private function formatSecret(string $upstreamOrder, string $service, int $quantity, string $link, array $result): string
    {
        $rows = [
            '上游订单已提交',
            '上游订单号：' . $upstreamOrder,
            '服务ID：' . $service,
            '数量：' . $quantity,
        ];

        if ($link !== '') {
            $rows[] = '链接：' . $link;
        }

        if (isset($result['status'])) {
            $rows[] = '状态：' . (string)$result['status'];
        }

        return implode(PHP_EOL, $rows);
    }

    private function formatStatusSecret(string $upstreamOrder, array $status, array $cache): string
    {
        $accounts = $status['accounts'] ?? null;
        if (is_array($accounts) && count($accounts) > 0) {
            return implode(PHP_EOL, array_map('strval', $accounts));
        }

        $rows = [
            '上游订单处理中',
            '上游订单号：' . $upstreamOrder,
            '服务ID：' . (string)($cache['service'] ?? ''),
        ];

        foreach (['status' => '状态', 'charge' => '扣费', 'remains' => '剩余', 'currency' => '币种'] as $key => $label) {
            if (isset($status[$key]) && is_scalar($status[$key])) {
                $rows[] = $label . '：' . (string)$status[$key];
            }
        }

        return implode(PHP_EOL, array_filter($rows, static fn($row) => trim((string)$row) !== ''));
    }

    private function log(string $message): void
    {
        Plugin::log(self::PLUGIN, $message);
    }
}
