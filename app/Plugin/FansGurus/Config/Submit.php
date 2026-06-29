<?php
declare(strict_types=1);

return [
    [
        'title' => 'API地址',
        'name' => 'api_url',
        'type' => 'input',
        'placeholder' => 'https://fansgurus.com/api/v2',
        'required' => true,
    ],
    [
        'title' => 'API Key',
        'name' => 'api_key',
        'type' => 'password',
        'placeholder' => '在 FansGurus 后台 API 页面复制 key',
        'required' => true,
    ],
    [
        'title' => '自动采购',
        'name' => 'enabled_trade',
        'type' => 'switch',
        'placeholder' => '开启|关闭',
    ],
    [
        'title' => '接管库存',
        'name' => 'enabled_stock',
        'type' => 'switch',
        'placeholder' => '开启|关闭',
    ],
    [
        'title' => '默认链接来源',
        'name' => 'default_link_source',
        'type' => 'input',
        'placeholder' => 'contact 或 widget:字段名',
    ],
    [
        'title' => '库存显示值',
        'name' => 'stock_value',
        'type' => 'number',
        'placeholder' => 'FansGurus 无库存接口时用于前台展示',
    ],
    [
        'title' => '请求超时秒数',
        'name' => 'timeout',
        'type' => 'number',
        'placeholder' => '30',
    ],
    [
        'title' => '商品映射JSON',
        'name' => 'mapping',
        'type' => 'textarea',
        'height' => 260,
        'placeholder' => '{"本地商品ID":{"service":上游服务ID,"link_source":"contact","quantity":"order_num"}}',
        'required' => true,
    ],
];
