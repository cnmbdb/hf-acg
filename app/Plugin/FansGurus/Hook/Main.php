<?php
declare(strict_types=1);

namespace App\Plugin\FansGurus\Hook;

use App\Consts\Hook;
use App\Model\Bill;
use App\Model\Commodity;
use App\Model\Order;
use App\Model\Pay;
use App\Model\User;
use App\Plugin\FansGurus\Service\Client;
use App\Plugin\FansGurus\Service\Sync;
use App\Plugin\Support\UpstreamCategoryStatus;
use App\Plugin\Support\UpstreamOrderMessage;
use App\Plugin\Support\UpstreamPriceRecalculator;
use App\Util\Plugin;
use Kernel\Annotation\Hook as HookPoint;
use Kernel\Annotation\Plugin as PluginState;
use Kernel\Exception\JSONException;
use Kernel\Plugin\Entity\Stock;
use Illuminate\Database\Capsule\Manager as Db;

class Main
{
    private const PLUGIN = 'FansGurus';
    private static bool $reconciliationScheduled = false;

    #[PluginState(state: PluginState::START)]
    public function start(): void
    {
        $syncResult = $this->syncOnStart();
        $changed = $this->setSyncedGoodsStatus(1);
        $this->log("插件已启动，{$syncResult}，已上架同步商品{$changed['commodities']}个、分类{$changed['categories']}个");
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

        // 配置保存与同步是两个独立动作。先保存新配置，避免新 Key 校验失败时
        // 控制器因异常提前返回，导致管理员看到“保存不了”。
        setConfig($config, BASE_PATH . '/app/Plugin/' . self::PLUGIN . '/Config/Config.php');

        $requestedSyncAction = (int)($map['sync_action'] ?? 0);
        $priceConfigChanged = $this->priceConfigChanged($map);
        // 全量同步会请求全部上游服务并更新本地商品。它必须由后台的同步按钮
        // 显式触发，不能因为保存 Key 等普通配置而阻塞页面。
        if ($requestedSyncAction > 0) {
            $config['sync_action'] = (string)$requestedSyncAction;
            try {
                (new Sync())->run($config);
            } catch (\Throwable $e) {
                // Key 已经写入配置。同步失败不能再把“保存配置”变成失败。
                $config['sync_action'] = '0';
                $config['last_sync_at'] = date('Y-m-d H:i:s');
                $config['last_sync_result'] = '配置已保存，但同步失败：' . $e->getMessage();
                setConfig($config, BASE_PATH . '/app/Plugin/' . self::PLUGIN . '/Config/Config.php');
                $this->log($config['last_sync_result']);
            }
        } elseif ($priceConfigChanged) {
            $this->recalculateSyncedPrices($config);
        }
    }

    private function priceConfigChanged(array $map): bool
    {
        foreach (['exchange_rate_auto', 'usd_to_cny', 'price_multiplier', 'price_plus', 'price_floor'] as $key) {
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
        $this->scheduleOrderStatusReconciliation();
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
        $this->scheduleOrderStatusReconciliation();
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

        $cachedOrder = $this->cachedUpstreamOrder((string)$order->trade_no);
        if ($cachedOrder !== null) {
            $this->log("跳过重复提交 trade_no={$order->trade_no} upstream_order={$cachedOrder}");
            return;
        }

        $lock = $this->acquireOrderLock((string)$order->trade_no);
        if ($lock === null) {
            $this->log("跳过并发提交 trade_no={$order->trade_no} reason=processing");
            return;
        }

        try {
            $cachedOrder = $this->cachedUpstreamOrder((string)$order->trade_no);
            if ($cachedOrder !== null) {
                $this->log("跳过重复提交 trade_no={$order->trade_no} upstream_order={$cachedOrder}");
                return;
            }

            $link = $this->resolveLink($mapping, $config, $order);
            $quantity = $this->resolveQuantity($mapping, $order);
            $client = new Client(
                $this->normalizeApiUrl((string)($config['api_url'] ?? 'https://fansgurus.com/api/v2')),
                trim((string)($config['api_key'] ?? '')),
                (int)($config['timeout'] ?? 30)
            );

            $this->log("提交订单 trade_no={$order->trade_no} commodity={$commodity->id} service={$service} quantity={$quantity} link={$link}");
            try {
                $result = $client->add($service, $link, $quantity);
            } catch (\Throwable $e) {
                $this->log("提交失败 trade_no={$order->trade_no} commodity={$commodity->id} service={$service} quantity={$quantity} error={$e->getMessage()}");
                throw $e;
            }

            if (!isset($result['order'])) {
                throw new JSONException('FansGurus 下单成功但未返回上游订单号');
            }

            $upstreamOrder = (string)$result['order'];
            $status = $this->confirmUpstreamCharge($client, $upstreamOrder);
            $order->secret = $this->formatSecret();
            $order->delivery_status = 1;
            $order->save();

            $this->updateOrderCache((string)$order->trade_no, [
                'trade_no' => $order->trade_no,
                'upstream_order' => $upstreamOrder,
                'service' => (string)$service,
                'quantity' => $quantity,
                'link' => $link,
                'response' => $result,
                'last_status' => $status,
                'last_checked_at' => date('Y-m-d H:i:s'),
                'balance_paid' => $pay?->handle === '#system',
                'owner_id' => (int)$order->owner,
                'local_amount' => (float)$order->amount,
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            $charge = (string)($status['charge'] ?? '');
            $statusLabel = (string)($status['status'] ?? '');
            $this->log("下单成功 trade_no={$order->trade_no} upstream_order={$upstreamOrder} status={$statusLabel} charge={$charge}");
        } finally {
            $this->releaseOrderLock($lock);
        }
    }

    private function scheduleOrderStatusReconciliation(): void
    {
        if (PHP_SAPI === 'cli' || self::$reconciliationScheduled) {
            return;
        }
        self::$reconciliationScheduled = true;

        register_shutdown_function(function (): void {
            try {
                $this->reconcilePendingOrders();
            } catch (\Throwable $e) {
                $this->log('订单状态回查失败：' . $e->getMessage());
            }
        });
    }

    /**
     * Confirm that FansGurus accepted the order and actually charged it before
     * the surrounding local balance transaction is allowed to commit.
     *
     * @throws JSONException
     */
    private function confirmUpstreamCharge(Client $client, string $upstreamOrder): array
    {
        $lastStatus = [];
        $lastError = null;
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $lastStatus = $client->status($upstreamOrder);
                $lastError = null;
            } catch (\Throwable $e) {
                $lastError = $e;
            }

            if ($lastError === null && $this->isUpstreamChargeConfirmed($lastStatus)) {
                return $lastStatus;
            }

            if ($lastError === null && $this->isFailedStatus($lastStatus)) {
                break;
            }

            if ($attempt < 2) {
                usleep(300000);
            }
        }

        $detail = $lastError?->getMessage()
            ?? sprintf(
                'status=%s charge=%s',
                (string)($lastStatus['status'] ?? 'unknown'),
                (string)($lastStatus['charge'] ?? 'missing')
            );
        $this->log("上游扣费确认失败 upstream_order={$upstreamOrder} {$detail}");
        throw new JSONException('FansGurus 未确认上游扣费，本地余额未扣除：' . $detail);
    }

    private function isUpstreamChargeConfirmed(array $status): bool
    {
        if ($this->isFailedStatus($status) || !isset($status['charge']) || !is_numeric($status['charge'])) {
            return false;
        }

        return (float)$status['charge'] > 0;
    }

    private function isFailedStatus(array $status): bool
    {
        return in_array(strtolower(trim((string)($status['status'] ?? ''))), [
            'canceled',
            'cancelled',
            'failed',
            'refunded',
            'rejected',
            'error',
        ], true);
    }

    private function isCompletedStatus(array $status): bool
    {
        return in_array(strtolower(trim((string)($status['status'] ?? ''))), [
            'completed',
            'complete',
        ], true);
    }

    private function reconcilePendingOrders(): void
    {
        $config = $this->config();
        if ((int)($config['enabled_trade'] ?? 0) !== 1 || trim((string)($config['api_key'] ?? '')) === '') {
            return;
        }

        $runLock = $this->acquireReconcileLock(60);
        if ($runLock === null) {
            return;
        }

        try {
            $orders = Plugin::getCaches(self::PLUGIN, 'orders', true);
            uasort($orders, static function ($a, $b): int {
                $left = is_array($a) ? (string)($a['last_checked_at'] ?? $a['created_at'] ?? '') : '';
                $right = is_array($b) ? (string)($b['last_checked_at'] ?? $b['created_at'] ?? '') : '';
                return strcmp($left, $right);
            });

            $client = new Client(
                $this->normalizeApiUrl((string)($config['api_url'] ?? 'https://fansgurus.com/api/v2')),
                trim((string)$config['api_key']),
                min(10, max(5, (int)($config['timeout'] ?? 10)))
            );
            $checked = 0;
            foreach ($orders as $tradeNo => $cache) {
                if ($checked >= 5 || !is_array($cache) || empty($cache['upstream_order'])) {
                    continue;
                }
                if (!empty($cache['failed_at']) || !empty($cache['refunded_at']) || !empty($cache['completed_at'])) {
                    continue;
                }
                $lastCheckedAt = strtotime((string)($cache['last_checked_at'] ?? '')) ?: 0;
                if ($lastCheckedAt > 0 && time() - $lastCheckedAt < 45) {
                    continue;
                }

                $orderLock = $this->acquireOrderLock((string)$tradeNo);
                if ($orderLock === null) {
                    continue;
                }

                try {
                    $checked++;
                    $status = $client->status((string)$cache['upstream_order']);
                    $changes = [
                        'last_status' => $status,
                        'last_checked_at' => date('Y-m-d H:i:s'),
                    ];

                    if ($this->isFailedStatus($status) && (float)($status['charge'] ?? 0) <= 0) {
                        $refunded = $this->refundCanceledBalanceOrder(
                            (string)$tradeNo,
                            (string)$cache['upstream_order'],
                            $status
                        );
                        $changes['terminal_status'] = (string)($status['status'] ?? 'Canceled');
                        $changes['failed_at'] = date('Y-m-d H:i:s');
                        if ($refunded) {
                            $changes['refunded_at'] = date('Y-m-d H:i:s');
                            $this->log("上游取消已退款 trade_no={$tradeNo} upstream_order={$cache['upstream_order']}");
                        }
                    } elseif ($this->isCompletedStatus($status)) {
                        $this->markOrderCompleted((string)$tradeNo, (string)$cache['upstream_order']);
                        $changes['terminal_status'] = (string)($status['status'] ?? 'Completed');
                        $changes['completed_at'] = date('Y-m-d H:i:s');
                    } else {
                        $this->updateProcessingMessage((string)$tradeNo, (string)$cache['upstream_order'], $status);
                    }

                    $this->updateOrderCache((string)$tradeNo, $changes);
                } catch (\Throwable $e) {
                    $this->log("查询订单失败 trade_no={$tradeNo} upstream_order={$cache['upstream_order']} error={$e->getMessage()}");
                    $this->updateOrderCache((string)$tradeNo, [
                        'last_check_error' => $e->getMessage(),
                        'last_checked_at' => date('Y-m-d H:i:s'),
                    ]);
                } finally {
                    $this->releaseOrderLock($orderLock);
                }
            }
        } finally {
            $this->releaseReconcileLock($runLock);
        }
    }

    private function refundCanceledBalanceOrder(string $tradeNo, string $upstreamOrder, array $status): bool
    {
        return Db::connection()->transaction(function () use ($tradeNo, $upstreamOrder, $status): bool {
            /** @var Order|null $order */
            $order = Order::query()->where('trade_no', $tradeNo)->lockForUpdate()->first();
            if (!$order || (float)$order->amount <= 0 || (int)$order->owner <= 0) {
                return false;
            }

            $payHandle = (string)Pay::query()->where('id', $order->pay_id)->value('handle');
            $statusLabel = (string)($status['status'] ?? 'Canceled');
            if ($payHandle !== '#system') {
                $order->delivery_status = 0;
                $order->secret = "FansGurus 上游订单 {$upstreamOrder} 已{$statusLabel}，请联系客服退款。";
                $order->save();
                return false;
            }

            $refundLog = "FansGurus上游取消退款[{$tradeNo}]";
            $alreadyRefunded = Bill::query()
                ->where('owner', $order->owner)
                ->where('currency', 0)
                ->where('type', Bill::TYPE_ADD)
                ->where('log', $refundLog)
                ->exists();

            if (!$alreadyRefunded) {
                /** @var User|null $buyer */
                $buyer = User::query()->where('id', $order->owner)->lockForUpdate()->first();
                if (!$buyer) {
                    return false;
                }
                Bill::create($buyer, (float)$order->amount, Bill::TYPE_ADD, $refundLog, 0, false);
            }

            $order->delivery_status = 0;
            $order->secret = "FansGurus 上游订单 {$upstreamOrder} 已{$statusLabel}，余额 {$order->amount} 已原路退回。";
            $order->save();
            return true;
        });
    }

    private function markOrderCompleted(string $tradeNo, string $upstreamOrder): void
    {
        $order = Order::query()->where('trade_no', $tradeNo)->first();
        if (!$order) {
            return;
        }
        $order->delivery_status = 1;
        $order->secret = "FansGurus 上游订单 {$upstreamOrder} 已完成。";
        $order->save();
    }

    private function updateProcessingMessage(string $tradeNo, string $upstreamOrder, array $status): void
    {
        $order = Order::query()->where('trade_no', $tradeNo)->first();
        if (!$order) {
            return;
        }
        $statusLabel = (string)($status['status'] ?? 'Processing');
        $remains = isset($status['remains']) ? '，剩余 ' . (string)$status['remains'] : '';
        $order->secret = "FansGurus 上游订单 {$upstreamOrder} 状态：{$statusLabel}{$remains}。";
        $order->save();
    }

    private function updateOrderCache(string $tradeNo, array $changes): void
    {
        $directory = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR);
        $path = $directory . '/acg-fansgurus-cache.lock';
        $handle = fopen($path, 'c+');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new JSONException('FansGurus 订单缓存锁定失败');
        }

        try {
            $current = Plugin::getCache(self::PLUGIN, 'orders', $tradeNo, true);
            Plugin::setCache(
                self::PLUGIN,
                'orders',
                $tradeNo,
                array_replace(is_array($current) ? $current : [], $changes),
                0,
                true
            );
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function acquireReconcileLock(int $intervalSeconds): ?array
    {
        $directory = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR);
        $path = $directory . '/acg-fansgurus-reconcile.lock';
        $handle = fopen($path, 'c+');
        if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            return null;
        }

        rewind($handle);
        $lastRun = (int)trim((string)stream_get_contents($handle));
        if ($lastRun > 0 && time() - $lastRun < $intervalSeconds) {
            flock($handle, LOCK_UN);
            fclose($handle);
            return null;
        }

        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, (string)time());
        fflush($handle);
        return ['handle' => $handle, 'path' => $path];
    }

    private function releaseReconcileLock(array $lock): void
    {
        $handle = $lock['handle'] ?? null;
        if (is_resource($handle)) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function cachedUpstreamOrder(string $tradeNo): ?string
    {
        $cache = Plugin::getCache(self::PLUGIN, 'orders', $tradeNo, true);
        if (!is_array($cache) || empty($cache['upstream_order'])) {
            return null;
        }

        return (string)$cache['upstream_order'];
    }

    private function acquireOrderLock(string $tradeNo): ?array
    {
        $directory = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR);
        if ($directory === '' || !is_dir($directory) || !is_writable($directory)) {
            throw new JSONException('FansGurus 系统临时目录不可写');
        }

        // Lock files belong to the PHP runtime, not the deployed code tree.
        // This avoids root-owned deployment/test directories blocking PHP-FPM.
        $path = $directory . '/acg-fansgurus-order-' . sha1(BASE_PATH . '|' . $tradeNo) . '.lock';
        $handle = fopen($path, 'c+');
        if ($handle === false) {
            throw new JSONException('FansGurus 无法创建订单锁');
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return null;
        }

        ftruncate($handle, 0);
        fwrite($handle, date('c'));
        fflush($handle);

        return ['handle' => $handle, 'path' => $path];
    }

    private function releaseOrderLock(array $lock): void
    {
        $handle = $lock['handle'] ?? null;
        if (is_resource($handle)) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        $path = (string)($lock['path'] ?? '');
        if ($path !== '' && is_file($path)) {
            @unlink($path);
        }
    }

    private function config(): array
    {
        return Plugin::getConfig(self::PLUGIN, false);
    }

    private function syncOnStart(): string
    {
        $config = $this->config();
        $config['sync_action'] = '3';
        $config['default_category_status'] = '1';
        $config['default_product_status'] = '1';

        try {
            $result = (new Sync())->run($config);
            return (string)($result['last_sync_result'] ?? '已同步上游分类和商品');
        } catch (\Throwable $e) {
            $this->log('插件启动同步失败：' . $e->getMessage());
            return '启动同步失败，已保留现有同步数据';
        }
    }

    private function setSyncedGoodsStatus(int $status): array
    {
        $categoryIds = Commodity::query()
            ->where('code', 'like', 'FG\_%')
            ->whereNotNull('category_id')
            ->distinct()
            ->pluck('category_id')
            ->filter()
            ->values()
            ->all();
        $categoryIds = $this->withParentCategoryIds($categoryIds);

        $commodities = Commodity::query()
            ->where('code', 'like', 'FG\_%')
            ->update(['status' => $status]);

        // A parent category can be shared even when its direct products are not.
        // Preserve the full path required by products from every active plugin.
        $activeCategoryTree = UpstreamCategoryStatus::activeCategoryTreeIds();
        $categories = 0;
        foreach ($categoryIds as $categoryId) {
            $categoryId = (int)$categoryId;
            if ($status === 1) {
                $categories += \App\Model\Category::query()
                    ->where('id', $categoryId)
                    ->update(['status' => 1]);
                continue;
            }

            if (isset($activeCategoryTree[$categoryId])) {
                continue;
            }

            $categories += \App\Model\Category::query()
                ->where('id', $categoryId)
                ->update(['status' => 0]);
        }

        return ['commodities' => $commodities, 'categories' => $categories];
    }

    private function withParentCategoryIds(array $categoryIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $categoryIds))));
        $frontier = $ids;
        while ($frontier) {
            $parents = \App\Model\Category::query()
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
        $config = $this->config();
        try {
            $mapping = $this->parseMapping((string)($config['mapping'] ?? ''));
        } catch (\Throwable) {
            $mapping = [];
        }

        $keys = [(string)$commodity->id, (string)$commodity->code];
        foreach ($keys as $key) {
            if (isset($mapping[$key]) && is_array($mapping[$key])) {
                return $mapping[$key];
            }
        }

        // Synced FansGurus commodities use FG_<service id> as their stable
        // code. Production config can be recreated or saved without the large
        // generated mapping, so derive the standard mapping from that code.
        if (!preg_match('/^FG_(\d+)$/i', trim((string)$commodity->code), $matches)) {
            return null;
        }

        $service = (int)$matches[1];
        if ($service <= 0) {
            return null;
        }

        $linkSource = trim((string)($config['default_link_source'] ?? ''));
        if ($linkSource === '' || $linkSource === 'contact') {
            $linkSource = 'widget:target_link';
        }

        return [
            'service' => $service,
            'link_source' => $linkSource,
            'quantity' => 'order_num',
            'stock' => (string)($config['stock_value'] ?? '999999'),
        ];
    }

    /**
     * @throws JSONException
     */
    private function resolveLink(array $mapping, array $config, Order $order): string
    {
        $source = trim((string)($mapping['link_source'] ?? $config['default_link_source'] ?? 'contact'));
        $widget = (array)json_decode((string)$order->widget, true);
        $targetLink = trim((string)($widget['target_link']['value'] ?? ''));

        if ($source === '' || $source === 'contact') {
            // 历史映射默认使用 contact；新页面会提交独立目标链接，优先使用它，
            // 保留 contact 作为兼容旧订单和旧商品的回退。
            $link = $targetLink !== '' ? $targetLink : (string)$order->contact;
        } elseif (str_starts_with($source, 'widget:')) {
            $name = substr($source, 7);
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

    private function formatSecret(): string
    {
        return UpstreamOrderMessage::PROCESSING;
    }

    private function log(string $message): void
    {
        $file = BASE_PATH . '/app/Plugin/' . self::PLUGIN . '/runtime.log';
        file_put_contents($file, '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL, FILE_APPEND);
    }
}
