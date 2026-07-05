<?php
declare(strict_types=1);

namespace App\View\User\Theme\Xiaohu;

use App\Consts\Render;

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
        ]
    ];

    const THEME = [
        "INDEX" => "Index/Index.html",
        "INDEX2" => "Index/Index2.html",
        "ITEM" => "Index/Item.html",
        "QUERY" => "Index/Query.html",
    ];
}
