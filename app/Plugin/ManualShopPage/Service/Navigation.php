<?php
declare(strict_types=1);

namespace App\Plugin\ManualShopPage\Service;

use App\Util\Plugin;

final class Navigation
{
    private const PLUGIN = 'ManualShopPage';
    private const DEFAULT_LABEL = '手动上架资源';
    private const DEFAULT_ICON = 'fa-duotone fa-regular fa-box-open';

    public static function viewData(): array
    {
        try {
            $config = Plugin::getConfig(self::PLUGIN, false);
        } catch (\Throwable) {
            $config = [];
        }

        $label = trim(strip_tags((string)($config['nav_label'] ?? self::DEFAULT_LABEL)));
        if ($label === '') {
            $label = self::DEFAULT_LABEL;
        }

        $icon = trim((string)($config['nav_icon'] ?? self::DEFAULT_ICON));
        $icon = trim((string)preg_replace('/[^A-Za-z0-9 _-]/', '', $icon));
        if ($icon === '' || !preg_match('/(?:^|\s)fa-[A-Za-z0-9_-]+(?:\s|$)/', $icon)) {
            $icon = self::DEFAULT_ICON;
        }

        return [
            'manualShopPageNavEnabled' => (int)($config['STATUS'] ?? 0) === 1,
            'manualShopPageNavLabel' => htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            'manualShopPageNavIcon' => $icon,
        ];
    }
}
