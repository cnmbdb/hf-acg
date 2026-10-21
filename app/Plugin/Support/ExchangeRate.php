<?php
declare(strict_types=1);

namespace App\Plugin\Support;

use App\Util\Http;
use Kernel\Exception\JSONException;

class ExchangeRate
{
    private const DEFAULT_MANUAL_RATE = 7.3;
    private const MIN_USD_CNY = 5.0;
    private const MAX_USD_CNY = 10.0;

    /**
     * @throws JSONException
     */
    public static function applyUsdToCny(array $config, string $pluginName): array
    {
        $auto = (int)($config['exchange_rate_auto'] ?? 0) === 1;
        if ($auto) {
            return self::applyAutoRate($config, $pluginName);
        }

        $rate = self::manualRate($config);
        $config['usd_to_cny'] = self::formatRate($rate);
        $config['exchange_rate_last_sync_at'] = date('Y-m-d H:i:s');
        $config['exchange_rate_last_sync_result'] = sprintf('手动汇率生效：1 USD = %s CNY', self::formatRate($rate));
        return $config;
    }

    public static function rate(array $config): float
    {
        $rate = trim((string)($config['usd_to_cny'] ?? ''));
        if ($rate !== '' && is_numeric($rate) && self::isValidRate((float)$rate)) {
            return (float)$rate;
        }

        return self::DEFAULT_MANUAL_RATE;
    }

    /**
     * @throws JSONException
     */
    private static function applyAutoRate(array $config, string $pluginName): array
    {
        try {
            [$rate, $source] = self::fetchUsdToCny();
            $config['usd_to_cny'] = self::formatRate($rate);
            $config['exchange_rate_last_sync_at'] = date('Y-m-d H:i:s');
            $config['exchange_rate_last_sync_result'] = sprintf('自动同步成功（%s）：1 USD = %s CNY', $source, self::formatRate($rate));
            return $config;
        } catch (\Throwable $e) {
            $existing = trim((string)($config['usd_to_cny'] ?? ''));
            if ($existing !== '' && is_numeric($existing) && self::isValidRate((float)$existing)) {
                $config['usd_to_cny'] = self::formatRate((float)$existing);
                $config['exchange_rate_last_sync_at'] = date('Y-m-d H:i:s');
                $config['exchange_rate_last_sync_result'] = sprintf(
                    '自动同步失败，沿用已有汇率 %s：%s',
                    self::formatRate((float)$existing),
                    $e->getMessage()
                );
                return $config;
            }

            throw new JSONException($pluginName . ' 自动汇率同步失败，且没有可用的有效汇率：' . $e->getMessage());
        }
    }

    /**
     * @return array{0: float, 1: string}
     */
    private static function fetchUsdToCny(): array
    {
        $sources = [
            'open.er-api.com' => [
                'url' => 'https://open.er-api.com/v6/latest/USD',
                'extract' => static fn(array $json): ?float => isset($json['rates']['CNY']) ? (float)$json['rates']['CNY'] : null,
            ],
            'frankfurter.app' => [
                'url' => 'https://api.frankfurter.app/latest?from=USD&to=CNY',
                'extract' => static fn(array $json): ?float => isset($json['rates']['CNY']) ? (float)$json['rates']['CNY'] : null,
            ],
        ];

        $errors = [];
        foreach ($sources as $name => $source) {
            try {
                $response = Http::make()->get($source['url'], ['timeout' => 8]);
                $body = trim((string)$response->getBody()->getContents());
                $json = json_decode($body, true);
                if (!is_array($json)) {
                    throw new \RuntimeException('返回不是 JSON');
                }

                $rate = $source['extract']($json);
                if ($rate === null || !self::isValidRate($rate)) {
                    throw new \RuntimeException('返回汇率无效');
                }

                return [$rate, $name];
            } catch (\Throwable $e) {
                $errors[] = $name . ': ' . $e->getMessage();
            }
        }

        throw new \RuntimeException(implode('；', $errors));
    }

    /**
     * @throws JSONException
     */
    private static function manualRate(array $config): float
    {
        $rate = trim((string)($config['usd_to_cny'] ?? ''));
        if ($rate === '') {
            $rate = (string)self::DEFAULT_MANUAL_RATE;
        }
        if (!is_numeric($rate) || !self::isValidRate((float)$rate)) {
            throw new JSONException('手动美元兑人民币汇率无效，请填写 5 到 10 之间的数字');
        }

        return (float)$rate;
    }

    private static function isValidRate(float $rate): bool
    {
        return $rate >= self::MIN_USD_CNY && $rate <= self::MAX_USD_CNY;
    }

    private static function formatRate(float $rate): string
    {
        return number_format($rate, 4, '.', '');
    }
}
