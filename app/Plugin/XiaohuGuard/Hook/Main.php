<?php
declare(strict_types=1);

namespace App\Plugin\XiaohuGuard\Hook;

use App\Consts\Hook;
use App\Plugin\XiaohuGuard\Service\OverlayManager;
use App\Util\Plugin;
use Kernel\Annotation\Hook as HookPoint;
use Kernel\Annotation\Plugin as PluginState;

class Main
{
    private const PLUGIN = 'XiaohuGuard';

    #[PluginState(state: PluginState::START)]
    public function start(): void
    {
        $manager = new OverlayManager();
        if (!$manager->hasSnapshot()) {
            $manager->capture();
        }

        $result = $manager->restoreWhenXiaohuIsActive();
        $this->logResult('插件已启动', $result);
    }

    #[PluginState(state: PluginState::SAVE_CONFIG)]
    public function saveConfig(string $pluginName, array $map): void
    {
        if ($pluginName !== self::PLUGIN || (int)($map['auto_restore'] ?? 0) !== 1) {
            return;
        }

        $result = (new OverlayManager())->restoreWhenXiaohuIsActive();
        $this->logResult('配置已保存', $result);
    }

    #[HookPoint(point: Hook::KERNEL_INIT)]
    public function kernelInit(): void
    {
        $config = Plugin::getConfig(self::PLUGIN, false);
        if ((int)($config['auto_restore'] ?? 1) !== 1) {
            return;
        }

        $result = (new OverlayManager())->restoreWhenXiaohuIsActive();
        if (($result['restored'] ?? 0) > 0 || !empty($result['warning'])) {
            $this->logResult('自动检查', $result);
        }
    }

    private function logResult(string $prefix, array $result): void
    {
        $message = sprintf(
            '%s：状态=%s，恢复=%d，未变化=%d%s',
            $prefix,
            (string)($result['status'] ?? 'unknown'),
            (int)($result['restored'] ?? 0),
            (int)($result['unchanged'] ?? 0),
            empty($result['warning']) ? '' : '，提示=' . $result['warning']
        );
        Plugin::log(self::PLUGIN, $message);
    }
}
