<?php

return [
    // Support both legacy BROADCAST_DRIVER and Laravel's BROADCAST_CONNECTION.
    // Your .env uses BROADCAST_CONNECTION, so prefer it when present.
    'default' => env('BROADCAST_CONNECTION', env('BROADCAST_DRIVER', 'log')),

    'connections' => [
        'pusher' => [
            'driver' => 'pusher',
            'key' => env('PUSHER_APP_KEY'),
            'secret' => env('PUSHER_APP_SECRET'),
            'app_id' => env('PUSHER_APP_ID'),
            'options' => [
                'cluster' => env('PUSHER_APP_CLUSTER', 'mt1'),
                'useTLS' => true,
            ],
        ],

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],
    ],
];
