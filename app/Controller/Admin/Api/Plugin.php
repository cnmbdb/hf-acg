<?php
declare(strict_types=1);

namespace App\Controller\Admin\Api;

use App\Consts\Hook;
use App\Controller\Base\API\Manage;
use App\Interceptor\ManageSession;
use App\Model\Config as CFG;
use App\Util\Theme;
use Kernel\Annotation\Interceptor;
use Kernel\Annotation\Post;
use Kernel\Context\Interface\Request;
use Kernel\Exception\JSONException;
use Kernel\Waf\Filter;

#[Interceptor([ManageSession::class], Interceptor::TYPE_API)]
class Plugin extends Manage
{
    private const USER_THEME_CONFIG_KEYS = [
        'user_theme',
        'user_mobile_theme',
        'user_center_theme',
    ];

    /**
     * 普通插件启停不应该改动前台模板选择。
     */
    private function snapshotUserThemeConfig(): array
    {
        $snapshot = [];
        foreach (self::USER_THEME_CONFIG_KEYS as $key) {
            $snapshot[$key] = CFG::get($key);
        }
        return $snapshot;
    }

    private function restoreUserThemeConfig(array $snapshot): void
    {
        foreach ($snapshot as $key => $value) {
            CFG::put($key, $value);
        }
    }

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
            $plugins[$key]["HAS_CONFIG"] = isset($plugin[\App\Consts\Plugin::PLUGIN_SUBMIT]) && !empty($plugin[\App\Consts\Plugin::PLUGIN_SUBMIT]);
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

        foreach ($plugins as &$plugin) {
            $plugin[\App\Consts\Plugin::PLUGIN_SUBMIT] = [];
            $plugin[\App\Consts\Plugin::PLUGIN_CONFIG] = [
                'STATUS' => (int)($plugin[\App\Consts\Plugin::PLUGIN_CONFIG]['STATUS'] ?? 0),
                'top' => (int)($plugin[\App\Consts\Plugin::PLUGIN_CONFIG]['top'] ?? 0),
            ];
        }
        unset($plugin);

        return $this->json(200, 'success', ["list" => $plugins]);
    }

    /**
     * @return array
     * @throws JSONException
     */
    public function getConfig(): array
    {
        $id = $this->request->get("id") ?: $this->request->post("id");

        if (!$id) {
            throw new JSONException("插件不存在");
        }

        $plugin = \Kernel\Util\Plugin::getPlugin($id, false);
        if (!$plugin) {
            throw new JSONException("插件不存在");
        }

        $plugin["id"] = $id;
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
            $configFile = BASE_PATH . '/app/Plugin/' . $id . '/Config/Config.php';
            if ((int)$config['STATUS'] == 0 && $map['STATUS'] == 1) {
                $themeConfig = $this->snapshotUserThemeConfig();
                try {
                    _plugin_start($id);
                    \App\Util\Opcache::invalidate($configFile);
                    $hookConfig = is_file($configFile) ? require $configFile : [];
                    if (is_array($hookConfig)) {
                        $config = array_replace($config, $hookConfig);
                    }
                    $config['STATUS'] = 1;
                    setConfig($config, $configFile);
                } finally {
                    $this->restoreUserThemeConfig($themeConfig);
                }
                return $this->json(200, "插件已启动");
            } else if ((int)$config['STATUS'] == 1 && $map['STATUS'] == 0) {
                $themeConfig = $this->snapshotUserThemeConfig();
                try {
                    _plugin_stop($id);
                    \App\Util\Opcache::invalidate($configFile);
                    $hookConfig = is_file($configFile) ? require $configFile : [];
                    if (is_array($hookConfig)) {
                        $config = array_replace($config, $hookConfig);
                    }
                    $config['STATUS'] = 0;
                    setConfig($config, $configFile);
                } finally {
                    $this->restoreUserThemeConfig($themeConfig);
                }
                return $this->json(200, "插件已停止");
            }
        }

        $themeConfig = $this->snapshotUserThemeConfig();
        foreach ($map as $k => $v) {
            $config[$k] = is_scalar($v) ? urldecode((string)$v) : $v;
        }

        try {
            hook(Hook::ADMIN_API_PLUGIN_SAVE_CONFIG, $id, $map); //12/09-重写HOOK逻辑
            \Kernel\Util\Plugin::runHookState($id, \Kernel\Annotation\Plugin::SAVE_CONFIG, $id, $map);//2022/01/12新增插件保存配置逻辑，无需启用插件也可以hook

            $configFile = BASE_PATH . '/app/Plugin/' . $id . '/Config/Config.php';
            if (is_file($configFile)) {
                \App\Util\Opcache::invalidate($configFile);
                $hookConfig = require $configFile;
                if (is_array($hookConfig)) {
                    $config = array_replace($hookConfig, $config);
                    foreach ($map as $k => $v) {
                        $config[$k] = is_scalar($v) ? urldecode((string)$v) : $v;
                    }
                    if (in_array($id, ['FansGurus', 'HStockPlus'], true) && array_key_exists('sync_action', $hookConfig)) {
                        $config['sync_action'] = $hookConfig['sync_action'];
                    }
                }
            }
            setConfig($config, $configFile);
        } finally {
            $this->restoreUserThemeConfig($themeConfig);
        }
        return $this->json(200, '配置已生效');
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
        $config = $theme["setting"];
        foreach ($map as $k => $v) {
            $config[$k] = is_scalar($v) ? urldecode((string)$v) : $v;
        }
        setConfig($config, BASE_PATH . "/app/View/User/Theme/{$id}/Setting.php");
        return $this->json(200, '模板设置已生效');
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
        return $this->json(200, 'success');
    }

}
