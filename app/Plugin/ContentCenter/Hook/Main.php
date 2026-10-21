<?php
declare(strict_types=1);

namespace App\Plugin\ContentCenter\Hook;

use App\Consts\Hook;
use App\Plugin\ContentCenter\Service\Schema;
use App\Util\Plugin;
use Kernel\Annotation\Hook as HookPoint;
use Kernel\Annotation\Plugin as PluginState;

class Main
{
    private const PLUGIN = 'ContentCenter';

    #[PluginState(state: PluginState::START)]
    public function start(): void
    {
        Schema::ensure();
        Plugin::log(self::PLUGIN, '内容中心已启用，博客管理和页面管理已准备就绪');
    }

    #[HookPoint(point: Hook::ADMIN_VIEW_MENU)]
    public function adminMenu(): string
    {
        return '<div class="menu-item"><a class="menu-link" href="/plugin/ContentCenter/admin/page"><span class="menu-icon"><i class="fa-duotone fa-regular fa-newspaper"></i></span><span class="menu-title">博客管理</span></a></div>';
    }
}
