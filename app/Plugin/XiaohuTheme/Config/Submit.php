<?php
declare(strict_types=1);
$configPath=__DIR__.'/Config.php';
$config=is_file($configPath)?(array)require $configPath:[];
$legacyEnabled=(int)($config['components_enabled']??1);
$components=[
    ['name'=>'content_center_enabled','title'=>'内容中心（ContentCenter）','description'=>'控制内容中心插件；启用后会安装缺失文件并显示在通用插件中，关闭后停用并隐藏。'],
    ['name'=>'fans_gurus_enabled','title'=>'FansGurus 上游货源','description'=>'控制 FansGurus 插件；启用后会安装缺失文件并显示在通用插件中，关闭后停用并隐藏。'],
    ['name'=>'hstock_plus_enabled','title'=>'HStockPlus 上游货源','description'=>'控制 HStockPlus 插件；启用后会安装缺失文件并显示在通用插件中，关闭后停用并隐藏。'],
    ['name'=>'manual_shop_page_enabled','title'=>'手动商家页（ManualShopPage）','description'=>'控制手动商家页插件；启用后会安装缺失文件并显示在通用插件中，关闭后停用并隐藏。'],
    ['name'=>'xiaohu_guard_enabled','title'=>'小虎主题保护（XiaohuGuard）','description'=>'控制主题保护插件；启用后会安装缺失文件并显示在通用插件中，关闭后停用并隐藏。'],
];
$fields=[];
foreach ($components as $component) {
    $component['type']='switch';
    $component['placeholder']='启用|停用';
    // Fall back to the previous suite-wide setting on an upgraded v1.2.0 install.
    $component['default']=(int)($config[$component['name']]??$legacyEnabled);
    $component['tips']=$component['description'];
    unset($component['description']);
    $fields[]=$component;
}
return [
    ...$fields,
    ['title'=>'修复套件组件','name'=>'repair_components','type'=>'switch','placeholder'=>'修复缺失文件|仅保存设置','default'=>(int)($config['repair_components']??0),'tips'=>'打开后保存，将补齐缺失文件，不覆盖已有文件；同次提交的各插件开关也会分别生效。'],
];
