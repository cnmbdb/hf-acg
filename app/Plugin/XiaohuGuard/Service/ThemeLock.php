<?php
declare(strict_types=1);

namespace App\Plugin\XiaohuGuard\Service;

use App\Model\Config;

final class ThemeLock
{
    private const SHOP_THEME = 'Xiaohu';
    private const CENTER_THEME = 'Cartoon';

    /**
     * Keep the storefront on Xiaohu while leaving the account center on the
     * built-in theme (Xiaohu does not provide account-center templates).
     */
    public static function normalizeSettings(array $settings): array
    {
        $settings['user_theme'] = self::SHOP_THEME;
        $settings['user_mobile_theme'] = '0';

        if (empty($settings['user_center_theme']) || $settings['user_center_theme'] === self::SHOP_THEME) {
            $settings['user_center_theme'] = self::CENTER_THEME;
        }
        if (empty($settings['user_center_mobile_theme']) || $settings['user_center_mobile_theme'] === self::SHOP_THEME) {
            $settings['user_center_mobile_theme'] = '0';
        }

        return $settings;
    }

    public static function enforce(): void
    {
        $settings = self::normalizeSettings([
            'user_theme' => (string)Config::get('user_theme'),
            'user_mobile_theme' => (string)Config::get('user_mobile_theme'),
            'user_center_theme' => (string)Config::get('user_center_theme'),
            'user_center_mobile_theme' => (string)Config::get('user_center_mobile_theme'),
        ]);

        foreach ($settings as $key => $value) {
            if ((string)Config::get($key) !== (string)$value) {
                Config::put($key, (string)$value);
            }
        }
    }
}
