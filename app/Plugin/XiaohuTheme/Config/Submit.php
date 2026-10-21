<?php
declare(strict_types=1);
return [
    ['title'=>'启用小虎套件组件','name'=>'components_enabled','type'=>'switch','placeholder'=>'已启用|已停用','default'=>1,'tips'=>'打开并保存后，安装并启用 ContentCenter、FansGurus、HStockPlus、ManualShopPage、XiaohuGuard，并在通用插件中显示；关闭并保存后停用并隐藏这五个组件。开启时不会自动同步上游商品。'],
    ['title'=>'修复套件组件','name'=>'repair_components','type'=>'switch','placeholder'=>'修复缺失文件|仅保存设置','tips'=>'打开后保存，将补齐缺失文件；不覆盖已有文件。套件已启用时会同时应用上方的组件开关。'],
];
