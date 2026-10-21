<?php
declare(strict_types=1);
namespace App\Plugin\XiaohuTheme\Service;
use Kernel\Util\Binary;
use Kernel\Exception\JSONException;
final class Registry
{
    public static function set(string $name, bool $enabled): void
    {
        if (!in_array($name,array_merge(Suite::COMPONENTS,['XiaohuTheme']),true)) throw new JSONException('未知套件组件');
        $path=BASE_PATH.'/runtime/plugin/hook';
        if (!is_dir(dirname($path))) mkdir(dirname($path),0755,true);
        $lock=fopen(dirname($path).'/xiaohu-suite.lock','c');
        if (!$lock || !flock($lock,LOCK_EX)) throw new JSONException('无法锁定组件挂钩');
        try {
            $binary=Binary::inst(); $key=_plugin_get_hwid();
            $data=is_file($path) ? $binary->unpack((string)file_get_contents($path),$key) : [];
            if (!is_array($data)) throw new JSONException('挂钩缓存不可读，已停止修改');
            unset($data[$name]);
            if ($enabled) {
                $hooks=[];
                foreach (glob(BASE_PATH.'/app/Plugin/'.$name.'/Hook/*.php') ?: [] as $file) {
                    $class='App\\Plugin\\'.$name.'\\Hook\\'.basename($file,'.php');
                    if (!class_exists($class)) continue;
                    foreach ((new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                        foreach ($method->getAttributes(\Kernel\Annotation\Hook::class) as $attribute) {
                            $args=$attribute->getArguments();$point=$args['point']??$args[0]??null;
                            if (!is_int($point)) continue;
                            $hooks[$point][]=['namespace'=>'\\'.$class,'method'=>$method->getName(),'pluginName'=>$name];
                        }
                    }
                }
                if ($hooks) $data[$name]=$hooks;
            }
            $encoded=$binary->pack($data,$key);
            if ($encoded==='' || file_put_contents($path,$encoded,LOCK_EX)===false) throw new JSONException('组件挂钩保存失败');
        } finally { flock($lock,LOCK_UN);fclose($lock); }
    }
}
