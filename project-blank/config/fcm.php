<?php

return [
    'driver' => env('FCM_PROTOCOL', 'http'),
    'log_enabled' => true,

    'http' => [
        'server_key' => env('FCM_SERVER_KEY', 'Your FCM server key'),
        'sender_id' => env('FCM_SENDER_ID', 'Your sender id'),
        'server_send_url' => 'https://...',
        'server_group_url' => 'https://...',
        'timeout' => 30.0, // in second
    ],
];
