<?php
declare(strict_types=1);

return [
    'STATUS' => 0,
    'api_url' => 'https://fansgurus.com/api/v2',
    'api_key' => '',
    'enabled_trade' => 1,
    'enabled_stock' => 0,
    'default_link_source' => 'contact',
    'timeout' => '30',
    'stock_value' => '999999',
    'mapping' => "{\n  \"1\": {\n    \"service\": 123,\n    \"link_source\": \"contact\",\n    \"quantity\": \"order_num\"\n  }\n}",
    'top' => 0,
];
