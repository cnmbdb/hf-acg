<?php
declare(strict_types=1);

namespace App\Plugin\Support;

use App\Model\Commodity;
use App\Util\Ini;
use Kernel\Exception\JSONException;

class UpstreamPriceRecalculator
{
    /**
     * @throws JSONException
     */
    public static function recalculate(string $pluginName, array $config): array
    {
        $config = ExchangeRate::applyUsdToCny($config, $pluginName);

        return match ($pluginName) {
            'HStockPlus' => self::recalculateByConfig($config, 'HSP\_%', 'hstockplus_specs', true),
            'FansGurus' => self::recalculateByConfig($config, 'FG\_%', 'fansgurus_specs', false),
            default => ['config' => $config, 'updated' => 0, 'skipped' => 0],
        };
    }

    private static function recalculateByConfig(array $config, string $codeLike, string $section, bool $hstockplus): array
    {
        $updated = 0;
        $skipped = 0;
        $rate = ExchangeRate::rate($config);
        $multiplier = self::numberConfig($config, 'price_multiplier', 1.2);
        $plus = self::numberConfig($config, 'price_plus', 0);
        $floor = self::numberConfig($config, 'price_floor', 0.01);

        Commodity::query()
            ->where('code', 'like', $codeLike)
            ->orderBy('id')
            ->chunk(500, function ($commodities) use (&$updated, &$skipped, $config, $section, $hstockplus, $rate, $multiplier, $plus, $floor): void {
                foreach ($commodities as $commodity) {
                    $sourceRate = self::sourceRate((string)$commodity->config, $section, $hstockplus);
                    if ($sourceRate === null) {
                        $skipped++;
                        continue;
                    }

                    $cost = round(max(0, $sourceRate) * $rate, 4);
                    if ($hstockplus && self::isHStockSmm((string)$commodity->config) && (int)($config['smm_rate_per_1000'] ?? 1) === 1) {
                        $cost = round($cost / 1000, 4);
                    }
                    if (!$hstockplus) {
                        $cost = round($cost / 1000, 4);
                    }

                    $price = round(max($floor, ($cost * $multiplier) + $plus), 2);
                    $commodity->factory_price = $cost;
                    $commodity->price = $price;
                    $commodity->user_price = $price;
                    $commodity->description = self::refreshLocalPrice((string)$commodity->description, $price);
                    $commodity->save();
                    $updated++;
                }
            });

        return [
            'config' => $config,
            'updated' => $updated,
            'skipped' => $skipped,
        ];
    }

    private static function sourceRate(string $rawConfig, string $section, bool $hstockplus): ?float
    {
        try {
            $config = Ini::toArray($rawConfig);
        } catch (\Throwable) {
            return null;
        }

        $value = $hstockplus
            ? ($config[$section]['rate'] ?? null)
            : ($config[$section]['rate_per_1000'] ?? null);

        if ($value === null || !is_numeric($value)) {
            return null;
        }

        return (float)$value;
    }

    private static function isHStockSmm(string $rawConfig): bool
    {
        try {
            $config = Ini::toArray($rawConfig);
        } catch (\Throwable) {
            return false;
        }

        return (string)($config['hstockplus_specs']['entity_type'] ?? '') === 'smm';
    }

    private static function refreshLocalPrice(string $description, float $price): string
    {
        if ($description === '' || !str_contains($description, '本地售价')) {
            return $description;
        }

        $value = htmlspecialchars((string)$price . ' CNY / 件', ENT_QUOTES, 'UTF-8');
        $pattern = '#(<tr>\s*<td[^>]*>\s*本地售价\s*</td>\s*<td[^>]*>).*?(</td>\s*</tr>)#isu';
        return preg_replace($pattern, '$1' . $value . '$2', $description) ?? $description;
    }

    private static function numberConfig(array $config, string $key, float $default): float
    {
        $value = trim((string)($config[$key] ?? ''));
        if ($value === '' || !is_numeric($value)) {
            return $default;
        }

        return max(0, (float)$value);
    }
}
