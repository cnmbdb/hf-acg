<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$root=dirname(__DIR__,3);
$_GET['s']='install/rewrite';
$_SERVER['HTTP_HOST']=$argv[1] ?? 'localhost';
$_SERVER['REQUEST_URI']='/install/rewrite';
$_SERVER['REQUEST_METHOD']='GET';
$_SERVER['REMOTE_ADDR']='127.0.0.1';
ob_start();require $root.'/kernel/Kernel.php';ob_end_clean();
$suite=new App\Plugin\XiaohuTheme\Service\Suite();
$suite->install();
if (in_array('--enable',$argv,true)) {
 $suite->activate();setConfig(['STATUS'=>1],$root.'/app/Plugin/XiaohuTheme/Config/Config.php');
 echo "小虎主题套件及五个组件已启用\n";
} else echo "组件文件已安装；请到通用插件启用 Xiaohu 小虎主题套件\n";
