<?php
declare(strict_types=1);

namespace App\View\User\Theme\Xiaohu;

use App\Consts\Render;

\App\Plugin\XiaohuGuard\Service\ThemeBootstrap::boot();

interface Config
{
    const INFO = [
        "NAME" => "小虎清爽模板",
        "AUTHOR" => "Codex",
        "VERSION" => "1.0.0",
        "WEB_SITE" => "#",
        "DESCRIPTION" => "清爽分类列表首页模板",
        "RENDER" => Render::ENGINE_SMARTY
    ];

    const SUBMIT = [
        [
            "title" => "ICP备案号",
            "name" => "icp",
            "type" => "input",
            "placeholder" => "填写后将会在店铺底部显示ICP备案号，不填写则不显示。"
        ],
        [
            "title" => "商品详情页面 - 购买提醒",
            "name" => "item_purchase_notice",
            "type" => "textarea",
            "height" => 260,
            "placeholder" => "支持基础 HTML：p、br、ul、ol、li、strong、em、a 超链接。"
        ]
    ];

    const THEME = [
        "INDEX" => "Index/Index.html",
        "INDEX2" => "Index/Index2.html",
        "INDEX3" => "Index/Index.html",
        "ITEM" => "Index/Item.html",
        "QUERY" => "Index/Query.html",
    ];
}
