<?php
declare(strict_types=1);
namespace App\Plugin\XiaohuTheme\Service;
use App\Model\Config;
use Kernel\Plugin\Hook;
use Kernel\Util\Plugin;
use Kernel\Exception\JSONException;
final class Suite
{
    public const COMPONENTS = ['ContentCenter','FansGurus','HStockPlus','ManualShopPage','XiaohuGuard'];
    public const COMPONENT_SWITCHES = [
        'ContentCenter'=>'content_center_enabled',
        'FansGurus'=>'fans_gurus_enabled',
        'HStockPlus'=>'hstock_plus_enabled',
        'ManualShopPage'=>'manual_shop_page_enabled',
        'XiaohuGuard'=>'xiaohu_guard_enabled',
    ];
    private const THEMES = ['user_theme','user_mobile_theme'];
    private string $root;
    public function __construct(?string $root = null) { $this->root = rtrim($root ?? BASE_PATH, '/'); }
    public function resolveComponentStates(?array $config = null): array
    {
        if ($config === null) {
            $path=$this->root.'/app/Plugin/XiaohuTheme/Config/Config.php';
            $config=is_file($path)?(array)require $path:[];
        }
        // The old suite-wide switch remains a fallback for installations upgraded from v1.2.0.
        $legacyEnabled=(int)($config['components_enabled']??1)===1;
        $states=[];
        foreach (self::COMPONENTS as $name) {
            $key=self::COMPONENT_SWITCHES[$name];
            $states[$name]=array_key_exists($key,$config)?(int)$config[$key]===1:$legacyEnabled;
        }
        return $states;
    }
    public function filterPluginList(array $plugins): array
    {
        $states=$this->resolveComponentStates();
        return array_values(array_filter($plugins,static function(array $plugin) use($states): bool {
            $name=(string)($plugin['id']??$plugin['PLUGIN_NAME']??'');
            return !in_array($name,self::COMPONENTS,true)||($states[$name]??false);
        }));
    }
    private function locked(callable $operation): void
    {
        $dir=$this->root.'/runtime/XiaohuTheme';
        if (!is_dir($dir) && !mkdir($dir,0700,true)) throw new JSONException('无法创建套件状态目录');
        $lock=fopen($dir.'/install.lock','c');
        if (!$lock || !flock($lock,LOCK_EX)) throw new JSONException('无法锁定套件安装状态');
        try { $operation(); } finally { flock($lock,LOCK_UN); fclose($lock); }
    }
    public function install(): void { $this->locked(fn()=> $this->installFiles()); }
    private function installFiles(): void
    {
        $pairs=['Theme/Xiaohu'=>'app/View/User/Theme/Xiaohu','Support'=>'app/Plugin/Support'];
        foreach (self::COMPONENTS as $name) $pairs['Plugins/'.$name]='app/Plugin/'.$name;
        foreach ($pairs as $source=>$target) {
            $path=$this->root.'/app/Plugin/XiaohuTheme/Bundle/'.$source;
            if (!is_dir($path)) throw new JSONException('套件缺少组件：'.$source);
        }
        foreach ($pairs as $source=>$target) {
            $path=$this->root.'/app/Plugin/XiaohuTheme/Bundle/'.$source;
            $it=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path,\FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if (!$file->isFile() || $file->isLink()) continue;
                $destination=$this->root.'/'.$target.'/'.substr($file->getPathname(),strlen($path)+1);
                if (is_link($destination)) throw new JSONException('组件目标不能为符号链接');
                if (is_file($destination)) continue;
                if (!is_dir(dirname($destination)) && !mkdir(dirname($destination),0755,true)) throw new JSONException('无法创建组件目录');
                if (!copy($file->getPathname(),$destination)) throw new JSONException('无法安装组件文件');
            }
        }
        Registry::set('XiaohuTheme',true);
    }
    public function activate(): void
    {
        (new License($this->root))->assertAllowed();
        $this->locked(function(): void {
            $this->installFiles();
            $path=$this->root.'/runtime/XiaohuTheme/state.php';
            $state=is_file($path) ? require $path : [];
            $before=[];
            foreach (self::COMPONENTS as $name) $before[$name]=require $this->configPath($name);
            $themes=[];
            foreach (self::THEMES as $key) $themes[$key]=(string)Config::get($key);
            $hookPath=$this->root.'/runtime/plugin/hook';
            $hooks=is_file($hookPath) ? file_get_contents($hookPath) : null;
            $oldState=is_file($path) ? file_get_contents($path) : null;
            try {
                if (!$state) {
                    $state=['statuses'=>array_map(static fn($c)=>(int)($c['STATUS']??0),$before),'themes'=>$themes];
                    setConfig($state,$path,true); chmod($path,0600);
                }
                $this->applyComponentStates($this->resolveComponentStates());
                Config::put('user_theme','Xiaohu');
                Config::put('user_mobile_theme','0');
                \App\Util\Plugin::log('XiaohuTheme','小虎主题套件已启用，配套插件状态由各自开关控制，未触发上游同步');
            } catch (\Throwable $e) {
                foreach ($before as $name=>$config) setConfig($config,$this->configPath($name),true);
                foreach ($themes as $key=>$value) Config::put($key,$value);
                if ($hooks!==null) file_put_contents($hookPath,$hooks,LOCK_EX);
                elseif (is_file($hookPath)) unlink($hookPath);
                if ($oldState!==null) file_put_contents($path,$oldState,LOCK_EX);
                elseif (is_file($path)) unlink($path);
                throw new JSONException('套件启用失败，已恢复配置及挂钩：'.$e->getMessage());
            }
        });
    }
    public function setComponentStates(array $states, ?string $licenseCode = null): void
    {
        foreach ($states as $name => $enabled) {
            if (!in_array($name, self::COMPONENTS, true) || !$enabled) continue;
            $path = $this->configPath($name);
            $current = is_file($path) ? (array) require $path : [];
            if ((int)($current['STATUS'] ?? 0) !== 1) {
                (new License($this->root))->assertAllowed($licenseCode);
                break;
            }
        }
        $this->locked(function() use($states): void {
            $this->installFiles();
            $desired=$this->resolveComponentStates();
            foreach ($states as $name=>$enabled) {
                if (array_key_exists($name,$desired)) $desired[$name]=(bool)$enabled;
            }
            $before=[];
            foreach (self::COMPONENTS as $name) $before[$name]=require $this->configPath($name);
            $hookPath=$this->root.'/runtime/plugin/hook';
            $hooks=is_file($hookPath)?file_get_contents($hookPath):null;
            try {
                $this->applyComponentStates($desired);
            } catch (\Throwable $e) {
                foreach ($before as $name=>$config) setConfig($config,$this->configPath($name),true);
                if ($hooks!==null) file_put_contents($hookPath,$hooks,LOCK_EX);
                elseif (is_file($hookPath)) unlink($hookPath);
                throw new JSONException('配套插件开关更新失败，已恢复配置及挂钩：'.$e->getMessage());
            }
        });
    }
    private function applyComponentStates(array $states): void
    {
        foreach (self::COMPONENTS as $name) {
            $enabled=(bool)($states[$name]??false);
            setConfig(['STATUS'=>$enabled?1:0],$this->configPath($name));
            Registry::set($name,$enabled);
        }
        if ($states['ContentCenter']??false) {
            // ContentCenter only prepares local schema. Provider START hooks may sync remote products.
            Plugin::runHookState('ContentCenter',\Kernel\Annotation\Plugin::START);
        }
    }
    public function deactivate(): void
    {
        $this->locked(function(): void {
            $path=$this->root.'/runtime/XiaohuTheme/state.php';
            if (!is_file($path)) return;
            $state=require $path;
            $componentStates=$this->resolveComponentStates();
            foreach (self::COMPONENTS as $name) {
                $status=($componentStates[$name]??false)?(int)($state['statuses'][$name]??0):0;
                setConfig(['STATUS'=>$status],$this->configPath($name));
                Registry::set($name,$status===1);
            }
            if ((string)Config::get('user_theme')==='Xiaohu') {
                foreach (self::THEMES as $key) Config::put($key,(string)($state['themes'][$key]??''));
            }
            unlink($path);
        });
    }
    private function configPath(string $name): string { return $this->root.'/app/Plugin/'.$name.'/Config/Config.php'; }
}
