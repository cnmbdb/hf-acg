<?php
declare(strict_types=1);
namespace Kernel\Exception { class JSONException extends \RuntimeException {} }
namespace App\Model { final class Config { public static array $values=['user_theme'=>'Original','user_mobile_theme'=>'OtherMobile','user_center_theme'=>'Cartoon']; public static function get($key){return self::$values[$key]??'';} public static function put($key,$value){self::$values[$key]=$value;} } }
namespace Kernel\Annotation { class Plugin { const START=1; } }
namespace Kernel\Plugin { final class Hook { public static array $names=[]; public static function inst(){return new self;} public function add($n){self::$names[$n]=1;} public function del($n){unset(self::$names[$n]);} } }
namespace Kernel\Util { class Plugin { public static bool $fail=false; public static array $states=[]; public static function runHookState(...$args){self::$states[]=$args;if(self::$fail)throw new \RuntimeException('simulated schema failure');} } }
namespace App\Util { class Plugin { public static function log(...$args): void {} } }
namespace App\Plugin\XiaohuTheme\Service { class Registry { public static array $enabled=[]; public static function set(string $name,bool $enabled): void {self::$enabled[$name]=$enabled;} } }
namespace {
function setConfig(array $data,string $file,bool $reset=false):void { $old=(!$reset&&is_file($file))?require $file:[];file_put_contents($file,'<?php return '.var_export(array_replace($old,$data),true).';'); }
require dirname(__DIR__).'/app/Plugin/XiaohuTheme/Service/Suite.php';
use App\Plugin\XiaohuTheme\Service\Suite;
use App\Model\Config;
$root=sys_get_temp_dir().'/xiaohu-suite-test-'.bin2hex(random_bytes(6));mkdir($root,0700,true);
function check(bool $ok,string $name): void { if(!$ok)throw new RuntimeException($name);echo "PASS $name\n"; }
$bundle=$root.'/app/Plugin/XiaohuTheme/Bundle';
foreach(Suite::COMPONENTS as $name){mkdir($bundle.'/Plugins/'.$name.'/Config',0755,true);setConfig(['STATUS'=>0,'api_key'=>'TEST_ONLY_API_KEY'],$bundle.'/Plugins/'.$name.'/Config/Config.php');}
mkdir($bundle.'/Theme/Xiaohu',0755,true);file_put_contents($bundle.'/Theme/Xiaohu/Config.php','theme');mkdir($bundle.'/Support',0755,true);file_put_contents($bundle.'/Support/helper.php','helper');
$suite=new Suite($root);check($suite->componentsEnabled(),'legacy configuration keeps components enabled');$suite->install();
check(is_file($root.'/app/View/User/Theme/Xiaohu/Config.php'),'fresh installation installs theme');
check(!is_dir($root.'/app/View/User/Theme/Cartoon'),'Cartoon is never installed or modified');
foreach(Suite::COMPONENTS as $name)check(is_file($root.'/app/Plugin/'.$name.'/Config/Config.php'),'fresh install '.$name);
setConfig(['STATUS'=>1,'api_key'=>'TEST_ONLY_API_KEY','custom'=>'keep'],$root.'/app/Plugin/ContentCenter/Config/Config.php');
$suite->activate();$suite->activate();
foreach(Suite::COMPONENTS as $name){$c=require $root.'/app/Plugin/'.$name.'/Config/Config.php';check($c['STATUS']===1,'activate '.$name);}
$c=require $root.'/app/Plugin/ContentCenter/Config/Config.php';check($c['api_key']==='TEST_ONLY_API_KEY'&&$c['custom']==='keep','activation preserves existing configuration');
check(Config::$values['user_theme']==='Xiaohu'&&Config::$values['user_mobile_theme']==='0','activate theme and follow desktop');
check(Config::$values['user_center_theme']==='Cartoon','account center is untouched');
unlink($root.'/app/Plugin/ManualShopPage/Config/Config.php');$suite->install();check(is_file($root.'/app/Plugin/ManualShopPage/Config/Config.php'),'repair missing files');
$suite->deactivate();check(Config::$values['user_theme']==='Original'&&Config::$values['user_mobile_theme']==='OtherMobile','stop restores original themes after repeated start');
$c=require $root.'/app/Plugin/ContentCenter/Config/Config.php';check($c['STATUS']===1&&$c['api_key']==='TEST_ONLY_API_KEY','pre-enabled components and secrets preserved on stop');
$c=require $root.'/app/Plugin/FansGurus/Config/Config.php';check($c['STATUS']===0,'newly enabled component restored on stop');
$suite->deactivate();check(!is_file($root.'/runtime/XiaohuTheme/state.php'),'repeated stop is safe');
\Kernel\Util\Plugin::$fail=true;
try{$suite->activate();throw new RuntimeException('failure not thrown');}catch(\Kernel\Exception\JSONException $e){check(Config::$values['user_theme']==='Original','failure restores theme');$c=require $root.'/app/Plugin/FansGurus/Config/Config.php';check($c['STATUS']===0,'failure restores component status');check(!is_file($root.'/runtime/XiaohuTheme/state.php'),'failure leaves no active state');}
\Kernel\Util\Plugin::$fail=false;
$root2=sys_get_temp_dir().'/xiaohu-suite-switch-test-'.bin2hex(random_bytes(6));mkdir($root2,0700,true);
$bundle2=$root2.'/app/Plugin/XiaohuTheme/Bundle';
foreach(Suite::COMPONENTS as $name){mkdir($bundle2.'/Plugins/'.$name.'/Config',0755,true);setConfig(['STATUS'=>0],$bundle2.'/Plugins/'.$name.'/Config/Config.php');}
mkdir($bundle2.'/Theme/Xiaohu',0755,true);mkdir($bundle2.'/Support',0755,true);
mkdir($root2.'/app/Plugin/XiaohuTheme/Config',0755,true);setConfig(['STATUS'=>1,'components_enabled'=>0],$root2.'/app/Plugin/XiaohuTheme/Config/Config.php');
$suite2=new Suite($root2);check(!$suite2->componentsEnabled(),'fresh suite defaults components off');$suite2->install();$suite2->activate();
$rows=[['id'=>'XiaohuTheme'],...array_map(static fn($name)=>['id'=>$name],Suite::COMPONENTS)];
check(count($suite2->filterPluginList($rows))===1,'disabled components are hidden from generic plugin list');
foreach(Suite::COMPONENTS as $name){$c=require $root2.'/app/Plugin/'.$name.'/Config/Config.php';check($c['STATUS']===0,'master-off keeps '.$name.' disabled');}
$suite2->setComponentsEnabled(true);setConfig(['components_enabled'=>1],$root2.'/app/Plugin/XiaohuTheme/Config/Config.php');check(count($suite2->filterPluginList($rows))===6,'enabled components appear in generic plugin list');
foreach(Suite::COMPONENTS as $name){$c=require $root2.'/app/Plugin/'.$name.'/Config/Config.php';check($c['STATUS']===1&&\App\Plugin\XiaohuTheme\Service\Registry::$enabled[$name]===true,'master-on enables and registers '.$name);}
$suite2->setComponentsEnabled(false);setConfig(['components_enabled'=>0],$root2.'/app/Plugin/XiaohuTheme/Config/Config.php');check(count($suite2->filterPluginList($rows))===1,'master-off hides component rows again');
foreach(Suite::COMPONENTS as $name){$c=require $root2.'/app/Plugin/'.$name.'/Config/Config.php';check($c['STATUS']===0&&\App\Plugin\XiaohuTheme\Service\Registry::$enabled[$name]===false,'master-off disables and unregisters '.$name);}
$suite2->deactivate();foreach(Suite::COMPONENTS as $name){$c=require $root2.'/app/Plugin/'.$name.'/Config/Config.php';check($c['STATUS']===0,'stopping suite respects disabled master switch for '.$name);}
echo "ALL TESTS PASSED\n";
}
