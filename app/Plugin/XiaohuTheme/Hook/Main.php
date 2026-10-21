<?php
declare(strict_types=1);
namespace App\Plugin\XiaohuTheme\Hook;
use Kernel\Annotation\Plugin as State;
use App\Plugin\XiaohuTheme\Service\Suite;
final class Main
{
    // Only the suite switch uses this bridge; other plugin operations keep their native flow.
    // Core lifecycle helpers can acknowledge local plugins without applying their hooks.
    #[\Kernel\Annotation\Hook(point: \App\Consts\Hook::CONTROLLER_CALL_BEFORE)]
    public function control(object $controller, string $action): void
    {
        if (!$controller instanceof \App\Controller\Admin\Api\Plugin || $action !== 'setConfig') return;
        if (($_GET['id'] ?? $_POST['id'] ?? '') !== 'XiaohuTheme') return;
        $status=$_POST['STATUS'] ?? null;
        if (!in_array((string)$status,['0','1'],true)) return;
        $file=BASE_PATH.'/app/Plugin/XiaohuTheme/Config/Config.php';
        $current=require $file;
        $desired=(int)$status;
        if ((int)($current['STATUS']??0)===$desired) return;
        $suite=new Suite();
        if ($desired===1) $suite->activate(); else $suite->deactivate();
        setConfig(['STATUS'=>$desired],$file);
    }
    #[State(state: State::INSTALL)]
    public function install(): void { (new Suite())->install(); }
    #[State(state: State::START)]
    public function start(): void
    {
        (new Suite())->activate();
        setConfig(['STATUS'=>1],BASE_PATH.'/app/Plugin/XiaohuTheme/Config/Config.php');
    }
    #[State(state: State::STOP)]
    public function stop(): void
    {
        (new Suite())->deactivate();
        setConfig(['STATUS'=>0],BASE_PATH.'/app/Plugin/XiaohuTheme/Config/Config.php');
    }
    #[State(state: State::UNINSTALL)]
    public function uninstall(): void
    {
        (new Suite())->deactivate();
        setConfig(['STATUS'=>0],BASE_PATH.'/app/Plugin/XiaohuTheme/Config/Config.php');
    }
    #[State(state: State::SAVE_CONFIG)]
    public function saveConfig(string $pluginName, array $map): void
    {
        if ($pluginName !== 'XiaohuTheme' || (int)($map['repair_components'] ?? 0) !== 1) return;
        $suite = new Suite();
        $suite->install();
        $config = require BASE_PATH.'/app/Plugin/XiaohuTheme/Config/Config.php';
        if ((int)($config['STATUS'] ?? 0) === 1) $suite->activate();
    }
}
