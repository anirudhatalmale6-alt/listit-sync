<?php

return [
    'push_enabled' => env('LISTIT_PUSH_ENABLED', false),

    'ie' => [
        'api_url' => env('LISTIT_IE_API_URL', 'https://api.listit.ie'),
        'email' => env('LISTIT_IE_EMAIL'),
        'password' => env('LISTIT_IE_PASSWORD'),
        'placeholder_image_id' => env('LISTIT_IE_PLACEHOLDER_IMAGE_ID'),
    ],

    'im' => [
        'api_url' => env('LISTIT_IM_API_URL', 'https://api.listit.im'),
        'email' => env('LISTIT_IM_EMAIL'),
        'password' => env('LISTIT_IM_PASSWORD'),
        'placeholder_image_id' => env('LISTIT_IM_PLACEHOLDER_IMAGE_ID'),
    ],

    'scraping' => [
        'interval_minutes' => env('LISTIT_SCRAPE_INTERVAL', 15),
        'max_failures' => env('LISTIT_MAX_FAILURES', 10),
        'request_delay_ms' => env('LISTIT_REQUEST_DELAY', 500),
        'max_images_per_vehicle' => env('LISTIT_MAX_IMAGES', 10),
    ],

    'tiers' => [
        'free' => [
            'enabled' => env('LISTIT_FREE_TIER', true),
            'max_vehicles' => null, // unlimited
            'sync_interval' => 15, // minutes
        ],
        'paid' => [
            'enabled' => env('LISTIT_PAID_TIER', false),
            'max_vehicles' => null,
            'sync_interval' => 10,
            'price_monthly' => env('LISTIT_PAID_PRICE', 0),
        ],
    ],
];
