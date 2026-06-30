<?php
declare(strict_types=1);

namespace App\Plugin\FansGurus\Hook;

use App\Consts\Hook;
use App\Model\Commodity;
use App\Model\Order;
use App\Model\Pay;
use App\Plugin\FansGurus\Service\Client;
use App\Plugin\FansGurus\Service\Sync;
use App\Util\Plugin;
use Kernel\Annotation\Hook as HookPoint;
use Kernel\Annotation\Plugin as PluginState;
use Kernel\Exception\JSONException;
use Kernel\Plugin\Entity\Stock;

class Main
{
    private const PLUGIN = 'FansGurus';

    #[PluginState(state: PluginState::START)]
    public function start(): void
    {
        $changed = $this->setSyncedGoodsStatus(1);
        $this->log("插件已启动，已上架同步商品{$changed['commodities']}个、分类{$changed['categories']}个");
    }

    #[PluginState(state: PluginState::STOP)]
    public function stop(): void
    {
        $changed = $this->setSyncedGoodsStatus(0);
        $this->log("插件已停止，已下架同步商品{$changed['commodities']}个、分类{$changed['categories']}个");
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
        if ((int)($config['auto_sync_on_save'] ?? 0) === 1) {
            (new Sync())->run($config);
        }
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

        return new Stock((string)($mapping['stock'] ?? $config['stock_value'] ?? '999999'));
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
            throw new JSONException("FansGurus 商品 {$commodity->id} 未配置 service");
        }

        $link = $this->resolveLink($mapping, $config, $order);
        $quantity = $this->resolveQuantity($mapping, $order);
        $client = new Client(
            $this->normalizeApiUrl((string)($config['api_url'] ?? 'https://fansgurus.com/api/v2')),
            trim((string)($config['api_key'] ?? '')),
            (int)($config['timeout'] ?? 30)
        );

        $this->log("提交订单 trade_no={$order->trade_no} commodity={$commodity->id} service={$service} quantity={$quantity} link={$link}");
        $result = $client->add($service, $link, $quantity);

        if (!isset($result['order'])) {
            throw new JSONException('FansGurus 下单成功但未返回上游订单号');
        }

        $upstreamOrder = (string)$result['order'];
        $order->secret = $this->formatSecret($upstreamOrder, (string)$service, $quantity, $link);
        $order->delivery_status = 1;
        $order->save();

        Plugin::setCache(self::PLUGIN, 'orders', $order->trade_no, [
            'trade_no' => $order->trade_no,
            'upstream_order' => $upstreamOrder,
            'service' => (string)$service,
            'quantity' => $quantity,
            'link' => $link,
            'response' => $result,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->log("下单成功 trade_no={$order->trade_no} upstream_order={$upstreamOrder}");
    }

    private function config(): array
    {
        return Plugin::getConfig(self::PLUGIN, false);
    }

    private function setSyncedGoodsStatus(int $status): array
    {
        $categoryIds = Commodity::query()
            ->where('code', 'like', 'FG_%')
            ->whereNotNull('category_id')
            ->distinct()
            ->pluck('category_id')
            ->filter()
            ->values()
            ->all();

        $commodities = Commodity::query()
            ->where('code', 'like', 'FG_%')
            ->update(['status' => $status]);

        $categories = 0;
        if ($categoryIds) {
            $categories = \App\Model\Category::query()
                ->whereIn('id', $categoryIds)
                ->update(['status' => $status]);
        }

        return ['commodities' => $commodities, 'categories' => $categories];
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
            throw new JSONException('FansGurus 商品映射 JSON 格式不正确');
        }

        return $mapping;
    }

    private function mappingFor(Commodity $commodity): ?array
    {
        try {
            $mapping = $this->parseMapping((string)($this->config()['mapping'] ?? ''));
        } catch (\Throwable) {
            return null;
        }

        $keys = [(string)$commodity->id, (string)$commodity->code];
        foreach ($keys as $key) {
            if (isset($mapping[$key]) && is_array($mapping[$key])) {
                return $mapping[$key];
            }
        }

        return null;
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
        if ($link === '') {
            throw new JSONException("FansGurus 订单 {$order->trade_no} 未获取到链接参数");
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
            throw new JSONException("FansGurus 订单 {$order->trade_no} 数量必须大于 0");
        }

        return $num;
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

    private function formatSecret(string $upstreamOrder, string $service, int $quantity, string $link): string
    {
        return implode(PHP_EOL, [
            'FansGurus上游订单已提交',
            '上游订单号：' . $upstreamOrder,
            '服务ID：' . $service,
            '数量：' . $quantity,
            '链接：' . $link,
        ]);
    }

    private function log(string $message): void
    {
        $file = BASE_PATH . '/app/Plugin/' . self::PLUGIN . '/runtime.log';
        file_put_contents($file, '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL, FILE_APPEND);
    }
}
