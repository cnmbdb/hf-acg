<?php
declare(strict_types=1);

namespace App\Pay\Epay\Service;

use App\Model\Order;
use App\Pay\Epay\Impl\Signature;
use App\Service\Order as OrderService;
use App\Util\Http;
use App\Util\PayConfig;

final class Reconciler
{
    private const HANDLE = 'Epay';
    private const ORDER_COOLDOWN_SECONDS = 5;
    private const RECENT_COOLDOWN_SECONDS = 30;

    public static function reconcileTradeNo(string $tradeNo, OrderService $orderService, bool $force = false): bool
    {
        $tradeNo = trim($tradeNo);
        if ($tradeNo === '' || strlen($tradeNo) > 32 || !preg_match('/^[A-Za-z0-9_-]+$/', $tradeNo)) {
            return false;
        }

        $order = Order::with(['pay'])->where('trade_no', $tradeNo)->first();
        if (!$order || (int)$order->status !== 0 || !$order->pay || $order->pay->handle !== self::HANDLE) {
            return false;
        }

        if (!$force && !self::enterCooldown('order-' . sha1($tradeNo), self::ORDER_COOLDOWN_SECONDS)) {
            return false;
        }

        $config = PayConfig::config(self::HANDLE) ?: [];
        if ((int)($config['version'] ?? -1) !== 0
            || trim((string)($config['url'] ?? '')) === ''
            || trim((string)($config['pid'] ?? '')) === ''
            || trim((string)($config['key'] ?? '')) === '') {
            return false;
        }

        try {
            $response = Http::make()->get(rtrim((string)$config['url'], '/') . '/api.php', [
                'query' => [
                    'act' => 'order',
                    'pid' => (string)$config['pid'],
                    'key' => (string)$config['key'],
                    'out_trade_no' => $tradeNo,
                ],
                'connect_timeout' => 5,
                'timeout' => 8,
                'http_errors' => false,
            ]);
            $payload = json_decode(trim((string)$response->getBody()->getContents()), true);
        } catch (\Throwable $e) {
            self::log("订单{$tradeNo}查询失败：{$e->getMessage()}");
            return false;
        }

        if (!is_array($payload)) {
            self::log("订单{$tradeNo}查询结果格式异常");
            return false;
        }

        $callback = self::verifiedCallbackPayload($order, $payload, $config);
        if ($callback === null) {
            return false;
        }

        try {
            $orderService->callback(self::HANDLE, $callback);
            self::log("订单{$tradeNo}已通过网关主动回查补单");
            return true;
        } catch (\Throwable $e) {
            $paid = (int)(Order::query()->where('trade_no', $tradeNo)->value('status') ?? 0) === 1;
            if (!$paid) {
                self::log("订单{$tradeNo}补单失败：{$e->getMessage()}");
            }
            return $paid;
        }
    }

    public static function reconcileRecent(OrderService $orderService, ?int $owner = null, int $limit = 5): int
    {
        $scope = $owner === null ? 'admin' : 'owner-' . $owner;
        if (!self::enterCooldown('recent-' . sha1($scope), self::RECENT_COOLDOWN_SECONDS)) {
            return 0;
        }

        $query = Order::query()
            ->where('status', 0)
            ->where('create_time', '>=', date('Y-m-d H:i:s', time() - 7 * 86400))
            ->whereHas('pay', static function ($relation): void {
                $relation->where('handle', self::HANDLE);
            })
            ->orderByDesc('id');

        if ($owner !== null) {
            $query->where('owner', $owner);
        }

        $reconciled = 0;
        foreach ($query->limit(max(1, min(10, $limit)))->pluck('trade_no') as $tradeNo) {
            if (self::reconcileTradeNo((string)$tradeNo, $orderService, true)) {
                $reconciled++;
            }
        }

        return $reconciled;
    }

    public static function verifiedCallbackPayload(Order $order, array $payload, array $config): ?array
    {
        if ((int)($payload['code'] ?? 0) !== 1 || (string)($payload['status'] ?? '') !== '1') {
            return null;
        }

        $tradeNo = (string)($payload['out_trade_no'] ?? '');
        if ($tradeNo === '' || !hash_equals((string)$order->trade_no, $tradeNo)) {
            self::log("订单{$order->trade_no}回查订单号不匹配");
            return null;
        }

        if (isset($payload['pid']) && (string)$payload['pid'] !== (string)($config['pid'] ?? '')) {
            self::log("订单{$order->trade_no}回查商户号不匹配");
            return null;
        }

        if (!isset($payload['money']) || !is_numeric($payload['money'])) {
            return null;
        }

        $money = number_format((float)$payload['money'], 2, '.', '');
        $expected = number_format((float)$order->amount, 2, '.', '');
        if (!hash_equals($expected, $money)) {
            self::log("订单{$order->trade_no}回查金额不匹配");
            return null;
        }

        $callback = [
            'out_trade_no' => $tradeNo,
            'money' => $money,
            'trade_status' => 'TRADE_SUCCESS',
        ];
        $callback['sign'] = Signature::generateSignature($callback, (string)($config['key'] ?? ''));
        $callback['sign_type'] = 'MD5';
        return $callback;
    }

    private static function enterCooldown(string $name, int $seconds): bool
    {
        $path = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . 'acg-epay-reconcile-' . preg_replace('/[^A-Za-z0-9_-]/', '', $name) . '.lock';
        $handle = @fopen($path, 'c+');
        if ($handle === false) {
            return true;
        }

        try {
            if (!flock($handle, LOCK_EX | LOCK_NB)) {
                return false;
            }
            rewind($handle);
            $lastRun = (int)trim((string)stream_get_contents($handle));
            if ($lastRun > 0 && time() - $lastRun < $seconds) {
                return false;
            }
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string)time());
            fflush($handle);
            return true;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private static function log(string $message): void
    {
        if (defined('EPAY_RECONCILER_TESTING') && EPAY_RECONCILER_TESTING === true) {
            return;
        }
        PayConfig::log(self::HANDLE, 'RECONCILE', $message);
    }
}
