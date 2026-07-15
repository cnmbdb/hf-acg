<?php
declare (strict_types=1);

return [
    'version' => '1.0.0',
    'name' => 'BEpusdt',
    'author' => '#',
    'website' => '#',
    'description' => '#',
    'options' => [
        'usdt.trc20' => 'TRC20-USDT',
        'usdc.trc20' => 'TRC20-USDC',
        'tron.trx' => 'TRON-TRX',
        'usdt.erc20' => 'ERC20-USDT',
        'usdc.erc20' => 'ERC20-USDC',
        'ethereum.eth' => 'ETHEREUM-ETH',
        'usdt.polygon' => 'Polygon-USDT',
        'usdc.polygon' => 'Polygon-USDC',
        'usdt.bep20' => 'BEP20-USDT',
        'usdc.bep20' => 'BEP20-USDC',
        'bsc.bnb' => 'BSC-BNB',
        'usdt.aptos' => 'APTOS-USDT',
        'usdc.aptos' => 'APTOS-USDC',
        'usdt.solana' => 'SOLANA-USDT',
        'usdc.solana' => 'SOLANA-USDC',
        'usdt.xlayer' => 'XLAYER-USDT',
        'usdc.xlayer' => 'XLAYER-USDC',
        'usdt.arbitrum' => 'ARBITRUM-USDT',
        'usdc.arbitrum' => 'ARBITRUM-USDC',
        'usdc.base' => 'BASE-USDC',
        'usdt.plasma' => 'PLASMA-USDT'
    ],
    'callback' => [
        \App\Consts\Pay::IS_SIGN => true,
        \App\Consts\Pay::IS_STATUS => true,
        \App\Consts\Pay::FIELD_STATUS_KEY => 'status',
        \App\Consts\Pay::FIELD_STATUS_VALUE => 2,
        \App\Consts\Pay::FIELD_ORDER_KEY => 'order_id',
        \App\Consts\Pay::FIELD_AMOUNT_KEY => 'amount',
        \App\Consts\Pay::FIELD_RESPONSE => 'ok'
    ]
];