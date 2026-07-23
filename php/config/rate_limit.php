<?php
return [
    'enable' => (bool)env('RATE_LIMIT_ENABLE', true),

    'rules' => [
        'user' => [
            'limit_per_minute' => 60,
            'limit_per_hour' => 500,
        ],
        'player' => [
            'limit_per_minute' => 120,
            'limit_per_hour' => 1000,
        ],
        'admin' => [
            'limit_per_minute' => 300,
            'limit_per_hour' => 3000,
        ],
        'guest' => [
            'limit_per_minute' => 30,
            'limit_per_hour' => 200,
        ],
    ],

    'crawler' => [
        'enable' => true,
        'threshold_per_hour' => 300,
        'ban_duration' => 3600,
        'ban_key_prefix' => 'rate_limit:ban:',
    ],

    'sliding_window' => [
        'enable' => true,
        'window_size' => 60,
        'precision' => 10,
    ],

    'whitelist' => [
        'ips' => [
            '127.0.0.1',
        ],
        'user_ids' => [],
    ],

    'redis_key_prefix' => 'rate_limit:',
];
