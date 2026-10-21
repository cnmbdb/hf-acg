<?php
declare(strict_types=1);

namespace App\Controller\Admin\Api;

use App\Consts\Hook;
use App\Controller\Base\API\Manage;
use App\Interceptor\ManageSession;
use App\Model\ManageLog;
use App\Util\Theme;
use Kernel\Annotation\Interceptor;
use Kernel\Annotation\Post;
use Kernel\Context\Interface\Request;
use Kernel\Exception\JSONException;
use Kernel\Waf\Filter;

#[Interceptor([ManageSession::class], Interceptor::TYPE_API)]
class Plugin extends Manage
{
    /**
     * @return array
     */
    public function getPlugins(): array
    {
        $plugins = \Kernel\Util\Plugin::getPlugins(false);
        $appStore = (array)json_decode((string)file_get_contents(BASE_PATH . "/runtime/plugin/store.cache"), true);
        $path = BASE_PATH . "/app/Plugin/";

        $keywords = urldecode((string)$_POST['keywords']);
        $status = $_POST['equal-status'];

        foreach ($plugins as $key => $plugin) {

            $plugins[$key]["id"] = $plugin[\App\Consts\Plugin::PLUGIN_NAME];
            $plugins[$key]["HAS_CONFIG"] = !empty($plugin[\App\Consts\Plugin::PLUGIN_SUBMIT]);
            $plugins[$key][\App\Consts\Plugin::PLUGIN_SUBMIT] = [];
            $plugins[$key][\App\Consts\Plugin::PLUGIN_CONFIG] = [
                'STATUS' => $plugin[\App\Consts\Plugin::PLUGIN_CONFIG]['STATUS'] ?? 0,
                'top' => $plugin[\App\Consts\Plugin::PLUGIN_CONFIG]['top'] ?? 0,
            ];
            if (!array_key_exists($plugins[$key]["id"], $appStore)) {
                $plugins[$key]['icon'] = "/favicon.ico";
            } else {
                $plugins[$key]['icon'] = \App\Service\App::APP_URL . $appStore[$plugins[$key]["id"]]['icon'];

                if ($plugin['VERSION'] !== $appStore[$plugin['PLUGIN_NAME']]["version"]) {
                    $plugins[$key]['HAVE_UPDATE'] = true;
                }
            }

            //判断文档是否存在
            if (is_dir($path . $plugins[$key]["id"] . "/Wiki")) {
                $plugins[$key]['wiki'] = "/admin/plugin/wiki?plugin={$plugins[$key]["id"]}";
            }

            if ($status !== "" && $status !== null) {
                //未运行
                if ((int)$plugin[\App\Consts\Plugin::PLUGIN_CONFIG]['STATUS'] != $status) {
                    unset($plugins[$key]);
                }
            }


            if ($keywords) {
                if (!str_contains($plugin[\App\Consts\Plugin::NAME], $keywords) && !str_contains($plugin[\App\Consts\Plugin::DESCRIPTION], $keywords)) {
                    unset($plugins[$key]);
                }
            }
        }

        $plugins = array_values($plugins);

        usort($plugins, function ($a, $b) {
            $aTop = ($a['PLUGIN_CONFIG']['top'] ?? 0) == 1 ? 1 : 0;
            $bTop = ($b['PLUGIN_CONFIG']['top'] ?? 0) == 1 ? 1 : 0;
            return $bTop <=> $aTop;
        });

        usort($plugins, function ($a, $b) {
            return ($b['HAVE_UPDATE'] ?? false) <=> ($a['HAVE_UPDATE'] ?? false);
        });

        return $this->json(200, 'success', ["list" => $plugins]);
    }

    /**
     * @throws JSONException
     */
    public function getConfig(Request $request): array
    {
        $id = $request->post("id") ?: $request->get("id");
        if (!$id) {
            throw new JSONException("插件不存在");
        }

        $plugin = \Kernel\Util\Plugin::getPlugin((string)$id, false);
        if (!$plugin) {
            throw new JSONException("插件不存在");
        }

        $plugin["id"] = (string)$id;
        return $this->json(200, 'success', $plugin);
    }

    /**
     * @param Request $request
     * @return array
     * @throws JSONException
     * @throws \ReflectionException
     * @throws \Throwable
     */
    public function setConfig(Request $request): array
    {
        $map = $request->post(flags: Filter::NORMAL);

        $id = $request->get("id") ?: $request->post("id");

        if (!$id) {
            throw new JSONException("插件不存在");
        }

        if (isset($map['id'])) {
            unset($map['id']);
        }

        $plugin = \Kernel\Util\Plugin::getPlugin($id, false);

        if (!$plugin) {
            throw new JSONException("插件不存在");
        }
        $config = $plugin[\App\Consts\Plugin::PLUGIN_CONFIG];

        if (isset($map['STATUS'])) {
            if ((int)$config['STATUS'] == 0 && $map['STATUS'] == 1) {
                if ((string)$id === 'XiaohuTheme') {
                    (new \App\Plugin\XiaohuTheme\Service\Suite())->activate();
                } else {
                    _plugin_start($id);
                }
                $this->persistPluginStatus((string)$id, 1);
                ManageLog::log($this->getManage(), "启动了插件({$id})");
                return $this->json(200, "插件已启动");
            } else if ((int)$config['STATUS'] == 1 && $map['STATUS'] == 0) {
                if ((string)$id === 'XiaohuTheme') {
                    (new \App\Plugin\XiaohuTheme\Service\Suite())->deactivate();
                } else {
                    _plugin_stop($id);
                }
                $this->persistPluginStatus((string)$id, 0);
                ManageLog::log($this->getManage(), "停止了插件({$id})");
                return $this->json(200, "插件已停止");
            }
        }

        foreach ($map as $k => $v) {
            $config[$k] = is_scalar($v) ? urldecode((string)$v) : $v;
        }

        $configFile = BASE_PATH . '/app/Plugin/' . $id . '/Config/Config.php';
        $beforeHookConfigHash = is_file($configFile) ? (string)md5_file($configFile) : '';

        hook(Hook::ADMIN_API_PLUGIN_SAVE_CONFIG, $id, $map); //12/09-重写HOOK逻辑
        \Kernel\Util\Plugin::runHookState($id, \Kernel\Annotation\Plugin::SAVE_CONFIG, $id, $map);//2022/01/12新增插件保存配置逻辑，无需启用插件也可以hook

        $afterHookConfigHash = is_file($configFile) ? (string)md5_file($configFile) : '';
        if ($afterHookConfigHash !== '' && $afterHookConfigHash !== $beforeHookConfigHash) {
            $plugin = \Kernel\Util\Plugin::getPlugin($id, false);
            if ($plugin && isset($plugin[\App\Consts\Plugin::PLUGIN_CONFIG]) && is_array($plugin[\App\Consts\Plugin::PLUGIN_CONFIG])) {
                $config = array_replace($config, $plugin[\App\Consts\Plugin::PLUGIN_CONFIG]);
            }
        }

        setConfig($config, $configFile);
        ManageLog::log($this->getManage(), "修改了插件({$id})的配置");

        $message = '配置已生效';
        if ((int)($map['sync_action'] ?? 0) > 0) {
            $syncResult = trim((string)($config['last_sync_result'] ?? ''));
            if ($syncResult !== '') {
                $message = $syncResult;
            }
        }

        return $this->json(200, $message);
    }

    /**
     * 3.5.3 的插件启动/停止函数会执行状态钩子，但部分环境不会把 STATUS
     * 稳定写回插件配置。管理接口在钩子成功后补写并回读校验，避免页面提示
     * 成功、刷新后却仍显示原状态。
     *
     * @throws JSONException
     */
    private function persistPluginStatus(string $id, int $status): void
    {
        $plugin = \Kernel\Util\Plugin::getPlugin($id, false);
        if (!$plugin || !is_array($plugin[\App\Consts\Plugin::PLUGIN_CONFIG] ?? null)) {
            throw new JSONException('插件状态写入失败');
        }

        $config = $plugin[\App\Consts\Plugin::PLUGIN_CONFIG];
        $config['STATUS'] = (string)$status;
        $configFile = BASE_PATH . '/app/Plugin/' . $id . '/Config/Config.php';
        setConfig($config, $configFile);
        @unlink(BASE_PATH . '/runtime/plugin/plugin.cache');

        $saved = \Kernel\Util\Plugin::getPlugin($id, false);
        if ((int)($saved[\App\Consts\Plugin::PLUGIN_CONFIG]['STATUS'] ?? -1) !== $status) {
            throw new JSONException('插件状态写入失败');
        }
    }

    /**
     * @return array
     * @throws JSONException
     */
    public function setThemeConfig(): array
    {
        $map = $this->request->post(flags: Filter::NORMAL);
        $id = $this->request->get("id") ?: $this->request->post("id");

        if (!$id) {
            throw new JSONException("模板不存在");
        }

        if (isset($map['id'])) {
            unset($map['id']);
        }

        $theme = Theme::getConfig($id);
        if (empty($theme)) {
            throw new JSONException("模板不存在");
        }

        if ($id === 'Xiaohu' && array_key_exists('item_purchase_notice', $map)) {
            $map['item_purchase_notice'] = $this->sanitizeXiaohuPurchaseNotice((string)$map['item_purchase_notice']);
        }

        $config = $theme["setting"];
        foreach ($map as $k => $v) {
            $config[$k] = is_scalar($v) ? urldecode((string)$v) : $v;
        }
        setConfig($config, BASE_PATH . "/app/View/User/Theme/{$id}/Setting.php");
        ManageLog::log($this->getManage(), "修改了主题({$id})的配置");
        return $this->json(200, '模板设置已生效');
    }

    /**
     * Keep the public product reminder limited to simple, safe formatting.
     */
    private function sanitizeXiaohuPurchaseNotice(string $html): string
    {
        $config = \HTMLPurifier_Config::createDefault();
        $config->set('HTML.Allowed', 'a[href|target|rel],p,br,ul,ol,li,strong,b,em,i,u,span');
        $config->set('URI.AllowedSchemes', [
            'http' => true,
            'https' => true,
            'mailto' => true,
            'tel' => true,
        ]);

        return trim((new \HTMLPurifier($config))->purify($html));
    }

    /**
     * 获取插件日志
     * @param string $handle
     * @return array
     */
    public function getPluginLog(#[Post] string $handle): array
    {
        $pluginLog = \App\Util\Plugin::getPluginLog($handle);
        return $this->json(200, 'success', ['log' => $pluginLog]);
    }

    /**
     * @param string $handle
     * @return array
     */
    public function ClearPluginLog(#[Post] string $handle): array
    {
        \App\Util\Plugin::ClearPluginLog($handle);
        ManageLog::log($this->getManage(), "清空了插件({$handle})的运行日志");
        return $this->json(200, 'success');
    }

}
