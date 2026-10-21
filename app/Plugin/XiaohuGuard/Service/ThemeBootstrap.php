<?php
declare(strict_types=1);

namespace App\Plugin\XiaohuGuard\Service;

use App\Consts\Hook as HookPoint;
use App\Util\Plugin;
use Kernel\Plugin\Hook;

final class ThemeBootstrap
{
    private const PLUGIN = 'XiaohuGuard';

    private static bool $booted = false;

    /**
     * Theme files are discovered even when the plugin hook cache was cleared by
     * an official update. This tiny bridge re-registers the companion plugin in
     * the same web context and restores the overlay when Xiaohu is active.
     */
    public static function boot(): void
    {
        if (self::$booted || PHP_SAPI === 'cli') {
            return;
        }
        self::$booted = true;

        try {
            $config = Plugin::getConfig(self::PLUGIN, false);
            if ((int)($config['STATUS'] ?? 0) !== 1) {
                return;
            }

            self::ensureHooks([
                [self::PLUGIN, HookPoint::KERNEL_INIT, '\\App\\Plugin\\XiaohuGuard\\Hook\\Main', 'kernelInit'],
                ['FansGurus', HookPoint::SERVICE_SHOP_GET_ITEM_STOCK, '\\App\\Plugin\\FansGurus\\Hook\\Main', 'stock'],
                ['FansGurus', HookPoint::USER_API_ORDER_PAY_AFTER, '\\App\\Plugin\\FansGurus\\Hook\\Main', 'payAfter'],
                ['HStockPlus', HookPoint::SERVICE_SHOP_GET_ITEM_STOCK, '\\App\\Plugin\\HStockPlus\\Hook\\Main', 'stock'],
                ['HStockPlus', HookPoint::USER_API_ORDER_PAY_AFTER, '\\App\\Plugin\\HStockPlus\\Hook\\Main', 'payAfter'],
            ]);

            if ((int)($config['auto_restore'] ?? 1) === 1) {
                $result = (new OverlayManager())->restoreWhenXiaohuIsActive();
                if (($result['restored'] ?? 0) > 0 || !empty($result['warning'])) {
                    Plugin::log(
                        self::PLUGIN,
                        sprintf(
                            '主题引导检查：状态=%s，恢复=%d，未变化=%d%s',
                            (string)($result['status'] ?? 'unknown'),
                            (int)($result['restored'] ?? 0),
                            (int)($result['unchanged'] ?? 0),
                            empty($result['warning']) ? '' : '，提示=' . $result['warning']
                        )
                    );
                }
            }
        } catch (\Throwable $e) {
            Plugin::log(self::PLUGIN, '主题引导失败：' . $e->getMessage());
        }
    }

    private static function ensureHooks(array $hooks): void
    {
        if (!function_exists('_plugin_hook_exist')) {
            return;
        }

        foreach ($hooks as [$pluginName, $point, $namespace, $method]) {
            $pluginConfig = Plugin::getConfig($pluginName, false);
            if ((int)($pluginConfig['STATUS'] ?? 0) !== 1) {
                continue;
            }
            if (!Hook::inst()->exist($pluginName, $point, $namespace, $method)) {
                Hook::inst()->add($pluginName);
                Plugin::log(self::PLUGIN, "已重新注册伴生挂钩：{$pluginName}");
            }
        }
    }
}
