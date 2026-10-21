<?php
declare(strict_types=1);
$root=sys_get_temp_dir().'/xiaohu-registry-test-'.bin2hex(random_bytes(5));define('BASE_PATH',$root);
require '/app/vendor/autoload.php';
function _plugin_get_hwid(): string { return '1234567890123456'; }
function check(bool $ok,string $name):void { if(!$ok)throw new RuntimeException($name);echo "PASS $name\n"; }
mkdir($root.'/app/Plugin/XiaohuTheme/Hook',0755,true);mkdir($root.'/runtime/plugin',0755,true);
file_put_contents($root.'/app/Plugin/XiaohuTheme/Hook/Main.php','<?php namespace App\\Plugin\\XiaohuTheme\\Hook; final class Main { #[\\Kernel\\Annotation\\Hook(point:42)] public function fixture(): void {} }');
require $root.'/app/Plugin/XiaohuTheme/Hook/Main.php';
use App\Plugin\XiaohuTheme\Service\Registry;
use Kernel\Util\Binary;
$file=$root.'/runtime/plugin/hook';$binary=Binary::inst();$key=_plugin_get_hwid();
file_put_contents($file,$binary->pack(['Unrelated'=>[9=>[['pluginName'=>'Unrelated','namespace'=>'Original','method'=>'keep']]]],$key));
Registry::set('XiaohuTheme',true);Registry::set('XiaohuTheme',true);
$data=$binary->unpack(file_get_contents($file),$key);
check(isset($data['Unrelated'][9][0]),'unrelated plugin registrations preserved');
check(count($data['XiaohuTheme'][42])===1,'repeat registration does not duplicate hooks');
check($data['XiaohuTheme'][42][0]['namespace']==='\\App\\Plugin\\XiaohuTheme\\Hook\\Main','annotation resolves to framework namespace');
Registry::set('XiaohuTheme',false);$data=$binary->unpack(file_get_contents($file),$key);
check(!isset($data['XiaohuTheme'])&&isset($data['Unrelated']),'remove only target registration');
try{Registry::set('../bad',true);throw new RuntimeException('unknown accepted');}catch(Kernel\Exception\JSONException){echo "PASS unknown components rejected\n";}
file_put_contents($file,'corrupt-cache');set_error_handler(static fn()=>true);
try{Registry::set('XiaohuTheme',true);throw new RuntimeException('corrupt cache accepted');}catch(Kernel\Exception\JSONException){check(file_get_contents($file)==='corrupt-cache','unreadable cache is not overwritten');}finally{restore_error_handler();}
echo "ALL REGISTRY TESTS PASSED\n";
