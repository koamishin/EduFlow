<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Default driver
    |--------------------------------------------------------------------------
    | circle: shell out to `circle` + `arc-canteen` binaries via Symfony Process
    | developer / circle-rest: pure HTTP REST API via Circle Developer-Controlled Wallets
    | fake: in-memory ledger for tests / offline demo (no chain touch)
    */
    'default' => env('LEPTON_DRIVER', env('APP_ENV') === 'testing' ? 'fake' : 'circle'),

    'developer' => [
        'api_key' => env('LEPTON_CIRCLE_API_KEY', env('CIRCLE_API_KEY')),
        'entity_secret' => env('LEPTON_ENTITY_SECRET', env('CIRCLE_ENTITY_SECRET')),
        'wallet_set_id' => env('LEPTON_WALLET_SET_ID', env('CIRCLE_WALLET_SET_ID')),
        'base_url' => env('LEPTON_CIRCLE_API_URL', env('CIRCLE_API_URL', 'https://api.circle.com/v1/w3s')),
        'timeout' => 30,
    ],

    'webhooks' => [
        'enabled' => (bool) env('LEPTON_WEBHOOKS_ENABLED', false),
        'subscriptions' => array_values(array_filter(explode(',', (string) env('LEPTON_WEBHOOK_SUBSCRIPTIONS', '')))),
        'max_age_seconds' => (int) env('LEPTON_WEBHOOK_MAX_AGE_SECONDS', 86400),
        'api_origin' => 'https://api.circle.com',
    ],

    'circle' => [
        'bin' => env('LEPTON_CIRCLE_BIN', 'circle'),
        'timeout' => (int) env('LEPTON_CLI_TIMEOUT', 60),
    ],

    'arc' => [
        'bin' => env('LEPTON_ARC_BIN', 'arc-canteen'),
        'chain' => env('LEPTON_CHAIN', 'ARC-TESTNET'),
        // 5040 = Arc Mainnet, 5042 = Arc Testnet (legacy 5042002 also supported)
        'chain_id' => (int) env('LEPTON_CHAIN_ID', 5042),
        'treasury' => env('LEPTON_TREASURY_ADDRESS'),
        'rpc_url' => env('LEPTON_RPC_URL'),
        'timeout' => (int) env('LEPTON_CLI_TIMEOUT', 60),
    ],

    // USDC uses 6 decimals on Circle side, 18 decimals as Arc native gas.
    'usdc_decimals' => 6,

    // When true, transfers are validated but never broadcast (adds --estimate).
    'dry_run' => (bool) env('LEPTON_DRY_RUN', false),

    // When false, raw circle commands via lepton:circle or runCircle() are blocked.
    'allow_raw_commands' => (bool) env('LEPTON_ALLOW_RAW_CIRCLE', false),

    'explorer_url' => env('LEPTON_EXPLORER_URL', env('LEPTON_EXPLORER_TESTNET', 'https://testnet.arcscan.app')),

    'explorer' => [
        'testnet' => env('LEPTON_EXPLORER_TESTNET', 'https://testnet.arcscan.app'),
        'mainnet' => env('LEPTON_EXPLORER_MAINNET', 'https://explorer.arc.io'),
    ],

    'rpc' => [
        'mainnet' => env('ARC_MAINNET_RPC', 'https://rpc.mainnet.arc.io'),
        'testnet' => env('ARC_TESTNET_RPC', 'https://rpc.testnet.arc.io'),
    ],
];
