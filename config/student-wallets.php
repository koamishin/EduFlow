<?php

declare(strict_types=1);

return [
    'enabled' => (bool) env('STUDENT_CONTROLLED_WALLETS_ENABLED', true),
    'circle_api_key' => env('CIRCLE_USER_WALLETS_API_KEY'),
    'circle_app_id' => env('CIRCLE_USER_WALLETS_APP_ID'),
    'google_client_id' => env('CIRCLE_WALLETS_GOOGLE_CLIENT_ID'),
    'facebook_app_id' => env('CIRCLE_WALLETS_FACEBOOK_APP_ID'),
    'intent_minutes' => 10,
];
